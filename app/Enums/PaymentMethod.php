<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Card = 'card'; // reference: authorisation number
    case Cheque = 'cheque'; // reference: cheque number
    case Transfer = 'transfer'; // reference: transfer reference
    case Deposit = 'deposit'; // kept from the deposit at return (not a way to pay up front)

    /**
     * Methods a customer can use to pay or leave a deposit.
     *
     * @return list<string>
     */
    public static function customerMethods(): array
    {
        return [self::Cash->value, self::Card->value, self::Cheque->value, self::Transfer->value];
    }

    public function needsReference(): bool
    {
        return in_array($this, [self::Card, self::Cheque, self::Transfer], true);
    }
}
