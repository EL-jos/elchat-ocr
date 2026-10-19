<?php

namespace App\Services\WebsiteSecurityAuditor;

final class WebsiteSecurityFindingAnalyzer
{
    public function __construct(private readonly WebsiteSecurityRiskEngine $riskEngine)
    {
    }

    /** @param array<string, mixed> $page @param array<string, bool> $checks @return array<int, array<string, mixed>> */
    public function analyzePage(array $page, array $checks): array
    {
        $url = (string) ($page['url'] ?? '');
        $observedAt = (string) ($page['fetched_at'] ?? now()->toISOString());
        $metadata = is_array($page['security_observations'] ?? null) ? $page['security_observations'] : [];
        $headers = is_array($metadata['headers'] ?? null) ? $metadata['headers'] : [];
        $findings = [];

        if (($checks['tls_transport'] ?? false) && ($metadata['https'] ?? null) === false) {
            $findings[] = $this->finding(
                'insecure_transport', 'configuration', 'Transport HTTP sans chiffrement observé',
                'La page a été récupérée sur HTTP et n’a pas redirigé vers HTTPS.', 'high', $url,
                ['url' => $url, 'scheme' => 'http', 'observed_at' => $observedAt],
                'Configurer HTTPS et rediriger les requêtes HTTP vers HTTPS, puis vérifier le certificat et les redirections.',
                'Confirmer que le site et ses ressources sont servis exclusivement en HTTPS.',
                ['Requête HTTP observée sans redirection HTTPS.', 'Observation limitée à cette URL publique.'],
            );
        }

        if (($checks['security_headers'] ?? false)) {
            $https = (bool) ($metadata['https'] ?? false);
            $required = [
                'content-security-policy' => ['Content Security Policy absente', 'Une politique CSP n’a pas été observée dans la réponse HTTP.', 'low', 'Définir une Content-Security-Policy adaptée à l’application et vérifier les violations en mode report-only avant son application.'],
                'x-content-type-options' => ['Protection MIME absente', 'X-Content-Type-Options n’a pas été observé dans la réponse HTTP.', 'low', 'Ajouter X-Content-Type-Options: nosniff sur les réponses du site.'],
                'referrer-policy' => ['Politique de référent absente', 'Referrer-Policy n’a pas été observé dans la réponse HTTP.', 'informational', 'Définir une Referrer-Policy adaptée aux liens externes et aux données présentes dans les URLs.'],
                'permissions-policy' => ['Permissions Policy absente', 'Permissions-Policy n’a pas été observé dans la réponse HTTP.', 'informational', 'Définir une Permissions-Policy qui désactive les fonctionnalités navigateur non nécessaires.'],
            ];
            if ($https) {
                $required['strict-transport-security'] = ['HSTS absent', 'Strict-Transport-Security n’a pas été observé sur une réponse HTTPS.', 'low', 'Activer HSTS après validation des sous-domaines et de la durée de déploiement souhaitée.'];
            }

            foreach ($required as $header => [$title, $description, $severity, $remediation]) {
                if (! empty($headers[$header])) continue;
                $findings[] = $this->finding(
                    'missing_header_'.$header, 'configuration', $title, $description, $severity, $url,
                    ['url' => $url, 'header' => $header, 'observed' => 'absent', 'observed_at' => $observedAt],
                    $remediation,
                    'Récupérer de nouveau cette URL et vérifier la présence de l’en-tête dans la réponse finale.',
                    ['En-tête absent de la réponse observée.', 'Une politique applicative, CDN ou proxy peut définir cet en-tête sur d’autres chemins.'],
                );
            }

            if (empty($headers['x-frame-options']) && empty($headers['csp-frame-ancestors'])) {
                $findings[] = $this->finding(
                    'missing_frame_ancestors', 'configuration', 'Protection contre l’intégration en iframe non observée',
                    'Ni X-Frame-Options ni une directive frame-ancestors CSP n’ont été observés.', 'low', $url,
                    ['url' => $url, 'headers' => ['x-frame-options' => 'absent', 'csp_frame_ancestors' => 'absent'], 'observed_at' => $observedAt],
                    'Définir X-Frame-Options ou une directive CSP frame-ancestors compatible avec les intégrations attendues.',
                    'Vérifier la réponse et confirmer le comportement d’intégration prévu.',
                    ['Les deux protections ont été recherchées dans les en-têtes de cette réponse.'],
                );
            }
        }

        if (($checks['cookie_flags'] ?? false)) {
            $cookies = is_array($metadata['cookies'] ?? null) ? $metadata['cookies'] : [];
            foreach (['missing_secure' => ['Secure', 'medium'], 'missing_httponly' => ['HttpOnly', 'low'], 'missing_samesite' => ['SameSite', 'low']] as $key => [$flag, $severity]) {
                $count = (int) ($cookies[$key] ?? 0);
                if ($count < 1) continue;
                $findings[] = $this->finding(
                    'cookie_missing_'.strtolower($flag), 'configuration', 'Attribut de cookie '.$flag.' absent',
                    $count.' cookie(s) observé(s) ne déclarent pas l’attribut '.$flag.'. Les valeurs et noms des cookies ne sont pas conservés.',
                    $severity, $url,
                    ['url' => $url, 'cookie_count' => $count, 'attribute' => $flag, 'observed_at' => $observedAt],
                    'Ajouter l’attribut '.$flag.' aux cookies concernés après vérification du comportement attendu.',
                    'Relancer la requête et vérifier que les cookies observés incluent l’attribut '.$flag.'.',
                    ['Seuls les attributs des cookies Set-Cookie ont été comptés; leur contenu a été ignoré.'],
                );
            }
        }

        return $findings;
    }

    /** @param array<string, mixed> $evidence @param array<int, string> $riskReasons @return array<string, mixed> */
    private function finding(
        string $type,
        string $category,
        string $title,
        string $description,
        string $severity,
        string $asset,
        array $evidence,
        string $remediation,
        string $verification,
        array $riskReasons,
    ): array {
        $asset = $this->safeEvidenceUrl($asset);
        $evidence = $this->redactEvidenceUrls($evidence);
        $fingerprint = hash('sha256', implode('|', [$type, strtolower($asset)]));
        $evidenceId = 'http_observation:'.substr($fingerprint, 0, 24);

        return [
            'id' => $evidenceId,
            'fingerprint' => $fingerprint,
            'type' => $type,
            'category' => $category,
            'title' => $title,
            'description' => $description,
            'severity' => $severity,
            'status' => 'open',
            'detection_status' => 'detected',
            'validation_state' => 'confirmed',
            'ground_truth' => 'not_determinable',
            'asset' => $asset,
            'component' => null,
            'cve' => null,
            'cvss' => null,
            'epss' => null,
            'kev' => null,
            'reachability' => 'unknown',
            'exposure' => 'public',
            'exploitability' => 'unknown',
            'confidence' => 'high',
            'evidence' => [[
                'id' => $evidenceId,
                'source' => 'public_http_response',
                'type' => 'http_observation',
                'data' => $evidence,
                'observed_at' => $evidence['observed_at'] ?? now()->toISOString(),
            ]],
            'contextual_risk' => $this->riskEngine->assess($severity, $riskReasons),
            'remediation' => ['recommendation' => $remediation, 'verification_plan' => $verification, 'execution' => 'not_performed'],
            'references' => [],
            'related_findings' => [],
            'related_attack_paths' => [],
        ];
    }

    private function safeEvidenceUrl(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) return '[url redacted]';
        $safe = strtolower((string) ($parts['scheme'] ?? 'https')).'://'.strtolower((string) $parts['host']);
        if (isset($parts['port'])) $safe .= ':'.(int) $parts['port'];
        return $safe.(string) ($parts['path'] ?? '/');
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function redactEvidenceUrls(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->redactEvidenceUrls($value);
            } elseif (is_string($value) && in_array((string) $key, ['url', 'page_url', 'root_url'], true)) {
                $data[$key] = $this->safeEvidenceUrl($value);
            }
        }
        return $data;
    }
}
