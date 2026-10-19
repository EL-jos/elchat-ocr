<?php

namespace App\Services\WebsiteSecurityAuditor;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\Eval_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/** Deterministic source-code signals. Every pattern remains review-required. */
final class WebsiteSecuritySourceCodeAnalysisService
{
    private const MAX_FILES = 100;

    private const MAX_BYTES = 5_242_880;

    /** @param array<int, array{path: string, source: string, content: string}> $files
     * @return array{findings: array<int, array<string, mixed>>, files_scanned: int, parse_errors: int, bytes_scanned: int}
     */
    public function analyze(array $files): array
    {
        $findings = [];
        $bytes = 0;
        $scanned = 0;
        $parseErrors = 0;
        foreach (array_slice($files, 0, self::MAX_FILES) as $file) {
            if (! is_array($file) || ! is_string($file['content'] ?? null) || ! is_string($file['path'] ?? null)) {
                continue;
            }
            $content = $file['content'];
            $length = strlen($content);
            if ($length === 0 || $bytes + $length > self::MAX_BYTES || str_contains($content, "\0")) {
                continue;
            }
            $bytes += $length;
            $scanned++;
            $path = $this->safePath($file['path']);
            $source = (string) ($file['source'] ?? 'authorized_source');
            $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
            $signals = [];

            foreach ($lines as $index => $line) {
                $lineNo = $index + 1;
                $this->lineSignals($line, $path, $source, $lineNo, $signals);
            }

            if (str_ends_with(strtolower($path), '.php') && class_exists(ParserFactory::class)) {
                try {
                    $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($content) ?? [];
                    $finder = new NodeFinder;
                    foreach ($finder->findInstanceOf($ast, Eval_::class) as $node) {
                        $this->addSignal($signals, 'dynamic_code_evaluation', 'Évaluation dynamique de code à examiner', 'medium', $node->getStartLine());
                    }
                    foreach ($finder->findInstanceOf($ast, FuncCall::class) as $node) {
                        $name = $node->name instanceof Node\Name ? strtolower($node->name->toString()) : '';
                        $rule = match ($name) {
                            'exec', 'system', 'shell_exec', 'passthru', 'popen', 'proc_open' => ['process_execution', 'Appel de processus système à examiner', 'medium'],
                            'unserialize' => ['unsafe_deserialization', 'Désérialisation PHP à examiner', 'medium'],
                            'md5', 'sha1' => ['weak_hash_function', 'Fonction de hachage faible détectée', 'low'],
                            default => null,
                        };
                        if ($rule) {
                            $this->addSignal($signals, $rule[0], $rule[1], $rule[2], $node->getStartLine());
                        }
                    }
                } catch (Error) {
                    $parseErrors++;
                }
            }

            foreach ($signals as $signal) {
                $lineText = (string) ($lines[max(0, $signal['line'] - 1)] ?? '');
                $fingerprint = hash('sha256', implode('|', [$source, $path, $signal['line'], $signal['rule'], hash('sha256', $lineText)]));
                $id = 'code:'.substr($fingerprint, 0, 24);
                $findings[] = [
                    'id' => $id, 'fingerprint' => $fingerprint,
                    'type' => 'source_code_security_signal', 'category' => 'code_analysis',
                    'title' => $signal['title'],
                    'description' => 'Un motif de code déterministe a été repéré. Ce signal nécessite une revue du flux de données et du contexte; il ne démontre pas à lui seul une vulnérabilité exploitable.',
                    'severity' => $signal['severity'], 'status' => 'review_required',
                    'detection_status' => 'detected', 'validation_state' => 'suspected',
                    'ground_truth' => 'not_determinable', 'asset' => $path,
                    'reachability' => 'unknown', 'exposure' => 'unknown', 'exploitability' => 'unknown',
                    'confidence' => 'low', 'source' => $source,
                    'remediation' => [
                        'recommendation' => $this->recommendation($signal['rule']),
                        'verification_plan' => 'Faire examiner le contexte et les chemins de données par un développeur; relancer l’analyse après une correction approuvée.',
                    ],
                    'evidence' => [[
                        'id' => $id, 'source' => 'bounded_source_file_read', 'type' => 'static_code_pattern',
                        'data' => [
                            'file' => $path, 'line' => $signal['line'], 'rule' => $signal['rule'],
                            'line_sha256' => hash('sha256', $lineText), 'code_excerpt' => null,
                            'untrusted_content' => true, 'secret_value_included' => false,
                        ],
                    ]],
                ];
            }
        }

        return ['findings' => $findings, 'files_scanned' => $scanned, 'parse_errors' => $parseErrors, 'bytes_scanned' => $bytes];
    }

    /** @param array<string, array{rule: string, title: string, severity: string, line: int}> $signals */
    private function lineSignals(string $line, string $path, string $source, int $lineNo, array &$signals): void
    {
        $checks = [
            'provider_secret_literal' => ['/(?:AKIA[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9_]{20,}|github_pat_[A-Za-z0-9_]{20,}|sk_live_[A-Za-z0-9]{16,}|-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----)/', 'Chaîne ressemblant à un secret dans le code', 'medium'],
            'hardcoded_credential' => ['/(?:password|passwd|api[_-]?key|client[_-]?secret|access[_-]?token)\s*(?:=>|=|:)\s*[\'"][^\'"]{8,}[\'"]\s*[,;]?/i', 'Identifiant codé en dur à examiner', 'medium'],
            'dynamic_code_evaluation' => ['/\beval\s*\(/i', 'Évaluation dynamique de code à examiner', 'medium'],
            'process_execution' => ['/\b(?:shell_exec|passthru|proc_open|popen|system|exec)\s*\(/i', 'Appel de processus système à examiner', 'medium'],
            'unsafe_deserialization' => ['/\bunserialize\s*\(/i', 'Désérialisation non fiable à examiner', 'medium'],
            'dynamic_sql' => ['/(?:whereRaw|selectRaw|orderByRaw|DB::raw|queryRaw|execute\s*\().{0,180}(?:\$|\+\s*[A-Za-z_$]|\brequest\s*\()/i', 'Requête SQL construite dynamiquement à examiner', 'medium'],
            'dynamic_file_access' => ['/(?:include|require|file_get_contents|readfile|unlink)\s*\(?\s*(?:\$request|request\s*\(|\$_(?:GET|POST|REQUEST))/i', 'Accès fichier lié à une entrée dynamique à examiner', 'medium'],
            'weak_hash_function' => ['/\b(?:md5|sha1)\s*\(/i', 'Fonction de hachage faible détectée', 'low'],
            'debug_enabled' => ['/(?:APP_DEBUG\s*=\s*true|[\'"]debug[\'"]\s*=>\s*true|debug:\s*true)/i', 'Mode debug activé dans un fichier analysé', 'medium'],
            'tls_verification_disabled' => ['/(?:verify_peer\s*=>\s*false|verify\s*[:=]\s*false|CURLOPT_SSL_VERIFYPEER\s*,\s*false)/i', 'Vérification TLS désactivée dans un fichier analysé', 'medium'],
            'wildcard_cors' => ['/(?:Access-Control-Allow-Origin\s*[:=]\s*[\'"]?\*|allowedOrigins\s*[:=]\s*\[[^\]]*[\'"]\*[\'"])/i', 'Origine CORS générique détectée', 'low'],
            'unsafe_html_sink' => ['/(?:\[innerHTML\]\s*=|dangerouslySetInnerHTML|v-html\s*=|echo\s+\$request\s*->)/i', 'Insertion HTML ou sortie utilisateur brute à examiner', 'medium'],
        ];
        foreach ($checks as $rule => [$pattern, $title, $severity]) {
            if (preg_match($pattern, $line) !== 1) {
                continue;
            }
            if ($rule === 'hardcoded_credential' && preg_match('/(?:example|sample|placeholder|changeme|your[_-]?)/i', $line)) {
                continue;
            }
            if ($rule === 'debug_enabled' && preg_match('/(?:example|sample|test|fixture)/i', $path)) {
                continue;
            }
            if ($rule === 'provider_secret_literal' && preg_match('/(?:example|sample|test|fixture)/i', $path)) {
                continue;
            }
            $this->addSignal($signals, $rule, $title, $severity, $lineNo);
        }
    }

    /** @param array<string, array{rule: string, title: string, severity: string, line: int}> $signals */
    private function addSignal(array &$signals, string $rule, string $title, string $severity, int $line): void
    {
        $key = $rule.'|'.$line;
        $signals[$key] ??= compact('rule', 'title', 'severity', 'line');
    }

    private function recommendation(string $rule): string
    {
        return match ($rule) {
            'provider_secret_literal', 'hardcoded_credential' => 'Vérifier la validité sans afficher la valeur, déplacer le secret vers un coffre et envisager sa rotation si l’exposition est confirmée.',
            'process_execution' => 'Éviter la construction de commandes depuis des données; préférer une API typée ou une liste stricte d’arguments autorisés.',
            'unsafe_deserialization' => 'Éviter la désérialisation de données contrôlées par un utilisateur; utiliser un format de données non exécutable et valider son schéma.',
            'dynamic_sql' => 'Utiliser des requêtes paramétrées et vérifier que les identifiants SQL dynamiques proviennent d’une liste autorisée.',
            'dynamic_file_access' => 'Canonicaliser le chemin, le limiter à une racine autorisée et valider l’entrée avant tout accès fichier.',
            'weak_hash_function' => 'Vérifier l’usage. Pour mots de passe utiliser Argon2id/bcrypt; pour intégrité cryptographique, utiliser une primitive moderne adaptée.',
            'debug_enabled' => 'Confirmer que le mode debug est désactivé dans les environnements exposés et ne révèle pas les traces ou variables.',
            'tls_verification_disabled' => 'Réactiver la vérification du certificat et du nom d’hôte TLS.',
            'wildcard_cors' => 'Limiter les origines CORS aux domaines nécessaires et vérifier la combinaison avec les credentials.',
            'unsafe_html_sink' => 'Échapper le contenu selon le contexte ou utiliser un assainisseur HTML fiable avant rendu.',
            default => 'Faire examiner le contexte et les données d’entrée par un développeur avant de conclure.',
        };
    }

    private function safePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#(^|/)\.\.(?=/|$)#', '$1[redacted]', $path) ?: $path;

        return substr($path, 0, 240);
    }
}
