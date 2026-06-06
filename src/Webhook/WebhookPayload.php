<?php

namespace Kicol\FullFlow\Webhook;

/**
 * Normalização do envelope de webhook PT/EN (CL-7, sincronizacao-real-time
 * 4.9 — cutover B-coordenado):
 *
 *  - PT (legado, tráfego atual):  evento_id / evento / dados / assinatura_id / referencia_externa
 *  - EN (contrato novo, F9):      event_id / event_type / data / subscription_id / external_reference
 *
 * O formato é detectado, não misturado: cada campo lê primeiro a chave do
 * contrato novo e cai para a legada — campos obrigatórios (id + type)
 * continuam obrigatórios em qualquer formato (validação no controller).
 *
 * format() alimenta o log de auditoria exigido pelo critério de saída da
 * 4.9 (janela de observação: formato PT precisa zerar antes da v0.x que
 * remove o suporte legado).
 */
class WebhookPayload
{
    public static function eventId(array $payload): string
    {
        return (string) ($payload['event_id'] ?? $payload['evento_id'] ?? '');
    }

    public static function eventType(array $payload): string
    {
        return (string) ($payload['event_type'] ?? $payload['evento'] ?? '');
    }

    public static function data(array $payload): array
    {
        return (array) ($payload['data'] ?? $payload['dados'] ?? []);
    }

    public static function subscriptionId(array $payload): string
    {
        return (string) ($payload['subscription_id'] ?? $payload['assinatura_id'] ?? '');
    }

    public static function externalReference(array $payload): string
    {
        return (string) ($payload['external_reference'] ?? $payload['referencia_externa'] ?? '');
    }

    /** 'en', 'pt' ou 'unknown' — para o log de auditoria do cutover 4.9. */
    public static function format(array $payload): string
    {
        if (isset($payload['event_id']) || isset($payload['event_type'])) {
            return 'en';
        }

        if (isset($payload['evento_id']) || isset($payload['evento'])) {
            return 'pt';
        }

        return 'unknown';
    }
}
