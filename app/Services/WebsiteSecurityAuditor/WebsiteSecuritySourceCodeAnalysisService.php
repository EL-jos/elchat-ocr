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
        $scannedFiles = [];
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
            $scannedFiles[] = $path;
            $source = (string) ($file['source'] ?? 'authorized_source');
            $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
            $signals = [];
            $insideBlockComment = false;
            $insideHtmlComment = false;

            foreach ($lines as $index => $line) {
                $lineNo = $index + 1;
                if ($this->isCommentOnlyLine($line, $insideBlockComment, $insideHtmlComment)) {
                    continue;
                }
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
                    'description' => $this->findingDescription($signal),
                    'severity' => $signal['severity'], 'status' => 'review_required',
                    'detection_status' => 'detected', 'validation_state' => 'suspected',
                    'ground_truth' => 'not_determinable', 'asset' => $path,
                    'reachability' => 'unknown', 'exposure' => 'unknown', 'exploitability' => 'unknown',
                    'confidence' => 'low', 'source' => $source,
                    'source_usage' => $this->sourceUsage($path),
                    'runtime_usage' => 'not_verified',
                    'remediation' => [
                        'recommendation' => $this->recommendation($signal['rule']),
                        'verification_plan' => 'Faire examiner le contexte et les chemins de données par un développeur; relancer l’analyse après une correction approuvée.',
                    ],
                    'evidence' => [[
                        'id' => $id, 'source' => 'bounded_source_file_read', 'type' => 'static_code_pattern',
                        'data' => [
                            'file' => $path, 'line' => $signal['line'], 'rule' => $signal['rule'],
                            'source_usage' => $this->sourceUsage($path), 'runtime_usage' => 'not_verified',
                            'line_sha256' => hash('sha256', $lineText), 'code_excerpt' => null,
                            'untrusted_content' => true, 'secret_value_included' => false,
                        ],
                    ]],
                ];
            }
        }

        return [
            'findings' => $findings,
            'files_scanned' => $scanned,
            'files' => array_values(array_unique($scannedFiles)),
            'parse_errors' => $parseErrors,
            'bytes_scanned' => $bytes,
        ];
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

    private function isCommentOnlyLine(string $line, bool &$insideBlockComment, bool &$insideHtmlComment): bool
    {
        $trimmed = trim($line);
        if ($trimmed === '') {
            return true;
        }

        if ($insideBlockComment) {
            $end = strpos($trimmed, '*/');
            if ($end === false) {
                return true;
            }
            $insideBlockComment = false;
            $trimmed = ltrim(substr($trimmed, $end + 2));
            if ($trimmed === '') {
                return true;
            }
        }
        if ($insideHtmlComment) {
            $end = strpos($trimmed, '-->');
            if ($end === false) {
                return true;
            }
            $insideHtmlComment = false;
            $trimmed = ltrim(substr($trimmed, $end + 3));
            if ($trimmed === '') {
                return true;
            }
        }

        if (str_starts_with($trimmed, '/*')) {
            if (! str_contains(substr($trimmed, 2), '*/')) {
                $insideBlockComment = true;
            }
            return true;
        }
        if (str_starts_with($trimmed, '<!--')) {
            if (! str_contains(substr($trimmed, 4), '-->')) {
                $insideHtmlComment = true;
            }
            return true;
        }

        return preg_match('/^(?:\/\/|--(?=\s|$)|#(?=\s|!|$)|;|\*\/|\*\s)/', $trimmed) === 1;
    }

    /** @param array{rule: string, title: string, severity: string, line: int} $signal */
    private function findingDescription(array $signal): string
    {
        return match ($signal['rule']) {
            'provider_secret_literal', 'hardcoded_credential' => 'Une ligne du fichier contient une valeur qui ressemble à un secret ou à un identifiant codé en dur. La valeur est masquée. Sa validité, ses permissions et son exposition effective ne sont pas vérifiées.',
            'process_execution' => 'Un appel à une fonction qui lance un processus a été repéré. Le scanner ne sait pas si des données contrôlées par un utilisateur atteignent ses arguments; une injection de commande n’est donc pas confirmée.',
            'dynamic_code_evaluation' => 'Un appel à eval a été repéré. L’origine de son entrée et son chemin d’exécution ne sont pas établis; l’exécution de contenu fourni par un attaquant n’est pas confirmée.',
            'unsafe_deserialization' => 'Un appel à unserialize a été repéré. Le scanner ne peut pas établir si la donnée désérialisée est contrôlée par un utilisateur; l’exploitation n’est pas confirmée.',
            'dynamic_sql' => 'Une construction de requête SQL correspondant à un motif à risque a été repérée. Le scanner n’a pas vérifié le paramétrage de la requête ni le flux des entrées; une injection SQL n’est pas confirmée.',
            'dynamic_file_access' => 'Un accès fichier semble lié à une entrée dynamique. Le chemin complet, sa validation et sa portée d’exécution n’ont pas été vérifiés; une traversée de répertoire n’est pas confirmée.',
            'weak_hash_function' => 'Un appel à MD5 ou SHA-1 a été repéré. Le contexte d’utilisation n’est pas connu; le constat ne signifie pas que cette fonction protège des mots de passe.',
            'debug_enabled' => 'Une configuration qui semble activer le mode debug a été repérée dans le fichier. La configuration réellement chargée en production n’a pas été vérifiée.',
            'tls_verification_disabled' => 'Une option qui désactive la vérification TLS a été repérée. Le scanner ne peut pas établir si ce chemin de code est exécuté ni dans quel environnement.',
            'wildcard_cors' => 'Une origine CORS générique a été repérée dans le code. La configuration active en production et les réponses réellement émises n’ont pas été vérifiées.',
            'unsafe_html_sink' => 'Un motif d’insertion HTML ou de sortie brute a été repéré. L’échappement effectué en amont et l’origine des données n’ont pas été vérifiés; une XSS n’est pas confirmée.',
            default => 'Un motif de sécurité a été repéré dans le fichier et nécessite une vérification du contexte, des entrées et du chemin d’exécution avant de conclure à une faille exploitable.',
        };
    }

    private function sourceUsage(string $path): string
    {
        return preg_match('#(^|/)(tests?|specs?|fixtures?|examples?|demos?)(/|$)#i', str_replace('\\', '/', $path)) === 1
            ? 'test_or_example_file'
            : 'application_or_configuration_candidate';
    }
}
