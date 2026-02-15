<?php

namespace App\Services\Telephony;

class TelephonyManager
{
    public function provider(): TelephonyProviderInterface
    {
        $enabled = (bool) config('features.telephony.enabled', false);

        if (! $enabled) {
            return new NullTelephonyProvider();
        }

        $provider = trim(mb_strtolower((string) config('features.telephony.provider', 'null')));

        if ($provider === 'twilio') {
            return new TwilioTelephonyProvider();
        }

        return new NullTelephonyProvider();
    }
}
