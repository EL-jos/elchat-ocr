<?php

namespace App\Services\ia;

class IntentClassifier
{
    public function classify(string $message): string
    {
        $msg = trim(mb_strtolower($message));

        $closing = [
            'merci', 'merci.', 'merci beaucoup',
            'ok merci', 'd\'accord merci',
            'parfait merci', 'c\'est bon merci'
        ];

        $confirmation = [
            'ok', 'd\'accord', 'super',
            'je comprends', 'parfait', 'très bien'
        ];

        $greetings = [
            'bonjour', 'salut', 'bonsoir'
        ];

        if (in_array($msg, $closing)) {
            return 'closing';
        }

        if (in_array($msg, $confirmation)) {
            return 'confirmation';
        }

        if (in_array($msg, $greetings)) {
            return 'greeting';
        }

        if (mb_strlen($msg) < 4) {
            return 'short_message';
        }

        return 'information_request';
    }
}
