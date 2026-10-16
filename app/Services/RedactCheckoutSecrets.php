<?php

namespace App\Services;

use Monolog\LogRecord;

class RedactCheckoutSecrets
{
    private function clean(mixed $value): mixed
    {
        if ($value instanceof \Throwable) return ['class' => $value::class, 'message' => $this->clean($value->getMessage()), 'file' => $value->getFile(), 'line' => $value->getLine(), 'trace' => $this->clean($value->getTraceAsString())];
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) $result[$key] = in_array(strtolower((string) $key), ['token', 'signature', 'quote_token', 'guest_token_encrypted', 'guest_token_hash', 'session_id'], true) ? '[redacted]' : $this->clean($item);
            return $result;
        }
        if (!is_string($value)) return $value;
        $value = preg_replace('/([?&](?:token|signature|quote_token|session_id)=)[^&\s"<>]*/i', '$1[redacted]', $value);
        $value = preg_replace('~https://checkout\.stripe\.com/[^\s"<>]+~i', '[redacted-checkout-url]', $value);
        return preg_replace('/\b[a-f0-9]{64}\b/i', '[redacted]', $value);
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(message: $this->clean($record->message), context: $this->clean($record->context), extra: $this->clean($record->extra));
    }
}
