<?php

namespace RiseTechApps\ApiKey\Support;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Impede que um webhook de cliente vire porta para a rede interna (SSRF).
 *
 * Sem isto, cadastrar `http://169.254.169.254/...` ou `http://localhost:6379`
 * faria o servidor do pacote chamar o serviço de metadados da nuvem ou o Redis
 * em nome do cliente. A checagem roda no cadastro e de novo a cada entrega,
 * porque o DNS pode mudar depois (DNS rebinding).
 *
 * Checar não basta para a entrega: o cliente HTTP resolveria o nome de novo, e
 * um DNS com TTL zero responde um IP público para a checagem e um interno para
 * a conexão. Por isso a entrega usa allowedAddresses() + curlResolve() e
 * conecta exatamente nos IPs que foram conferidos.
 */
class WebhookUrlGuard
{
    /**
     * Faixas que não são internet pública e que as flags do filter_var() do
     * PHP deixam passar: CGNAT (rede da operadora), tradução IPv4/IPv6 (NAT64,
     * SIIT, 6to4, Teredo), que embute um IPv4 qualquer, inclusive interno,
     * site-local (o IPv6 privado antigo), faixas de teste e documentação, e
     * multicast.
     */
    private const BLOCKED_RANGES = [
        '100.64.0.0/10',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '::ffff:0:0:0/96',
        '100::/64',
        '2001::/32',
        '2001:db8::/32',
        '2002::/16',
        'fec0::/10',
        'ff00::/8',
    ];

    public static function isAllowed(string $url): bool
    {
        return self::allowedAddresses($url) !== null;
    }

    /**
     * Os IPs conferidos para a URL, ou null se ela é recusada.
     *
     * Lista vazia quer dizer "nada a fixar": a checagem de rede está desligada
     * ou o host já é um IP literal.
     *
     * @return list<string>|null
     */
    public static function allowedAddresses(string $url): ?array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? null;

        if (! $host || ! in_array($scheme, self::schemes(), true)) {
            return null;
        }

        if (! config('api-key.webhooks.block_private_networks', true)) {
            return [];
        }

        $host = trim($host, '[]');
        $literal = (bool) filter_var($host, FILTER_VALIDATE_IP);

        $ips = $literal ? [$host] : self::resolve($host);

        if ($ips === []) {
            return null;
        }

        foreach ($ips as $ip) {
            if (! self::isPublic($ip)) {
                return null;
            }
        }

        return $literal ? [] : $ips;
    }

    public static function isPublic(string $ip): bool
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            && ! IpUtils::checkIp($ip, self::BLOCKED_RANGES);
    }

    /**
     * Entradas de CURLOPT_RESOLVE ("host:porta:ip1,ip2") que prendem a conexão
     * aos IPs já conferidos. O host continua na URL, então SNI, certificado e
     * header Host seguem os do cliente.
     *
     * @param  list<string>  $ips
     * @return list<string>
     */
    public static function curlResolve(string $url, array $ips): array
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? null;

        if (! $host || $ips === []) {
            return [];
        }

        $port = $parts['port'] ?? (strtolower($parts['scheme'] ?? '') === 'http' ? 80 : 443);
        $addresses = array_map(
            fn (string $ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$ip}]" : $ip,
            $ips,
        );

        return [$host.':'.$port.':'.implode(',', $addresses)];
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
