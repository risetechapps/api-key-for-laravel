<?php

namespace RiseTechApps\ApiKey\Support;

/**
 * Impede que um webhook de cliente vire porta para a rede interna (SSRF).
 *
 * Sem isto, cadastrar `http://169.254.169.254/...` ou `http://localhost:6379`
 * faria o servidor do pacote chamar o serviço de metadados da nuvem ou o Redis
 * em nome do cliente. A checagem roda no cadastro e de novo a cada entrega,
 * porque o DNS pode mudar depois (DNS rebinding).
 */
class WebhookUrlGuard
{
    public static function isAllowed(string $url): bool
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? null;

        if (! $host || ! in_array($scheme, self::schemes(), true)) {
            return false;
        }

        if (! config('api-key.webhooks.block_private_networks', true)) {
            return true;
        }

        $host = trim($host, '[]');

        $ips = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : self::resolve($host);

        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private static function schemes(): array
    {
        return config('api-key.webhooks.allow_http', false) ? ['https', 'http'] : ['https'];
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        $ips = [];

        foreach ([DNS_A, DNS_AAAA] as $type) {
            foreach (@dns_get_record($host, $type) ?: [] as $record) {
                $ips[] = $record['ip'] ?? $record['ipv6'] ?? null;
            }
        }

        return array_values(array_filter($ips));
    }
}
