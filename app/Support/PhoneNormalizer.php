<?php

namespace App\Support;

use Propaganistas\LaravelPhone\PhoneNumber;
use Throwable;

class PhoneNormalizer
{
    /**
     * @return array{phone_code: string|null, phone_number: string|null}
     */
    public static function splitForForm(?string $phone, string $defaultCountry = 'AU'): array
    {
        if ($phone === null || trim($phone) === '') {
            return ['phone_code' => null, 'phone_number' => null];
        }

        try {
            $phoneNumber = new PhoneNumber(trim($phone), $defaultCountry);

            if (! $phoneNumber->isValid()) {
                return ['phone_code' => null, 'phone_number' => null];
            }

            $libPhoneObject = $phoneNumber->toLibPhoneObject();

            return [
                'phone_code' => '+'.$libPhoneObject->getCountryCode(),
                'phone_number' => (string) $libPhoneObject->getNationalNumber(),
            ];
        } catch (Throwable) {
            return ['phone_code' => null, 'phone_number' => null];
        }
    }
}
