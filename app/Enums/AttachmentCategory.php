<?php

namespace App\Enums;

/**
 * What a file is. Each kind of record accepts its own categories.
 */
enum AttachmentCategory: string
{
    // Customer (ID documents: viewing them leaves a footprint)
    case IdFront = 'id_front'; // CIN front
    case IdBack = 'id_back'; // CIN back
    case Passport = 'passport';
    case LicenceFront = 'licence_front';
    case LicenceBack = 'licence_back';
    case InternationalPermit = 'international_permit';

    // Rental
    case SignedContract = 'signed_contract';
    case DepositProof = 'deposit_proof'; // cheque photo, card slip
    case PaymentProof = 'payment_proof';

    // Car document (insurance certificate, carte grise...) and damage
    case Scan = 'scan';
    case RepairInvoice = 'repair_invoice';

    // Anywhere
    case Photo = 'photo';
    case Other = 'other';

    /**
     * Categories accepted for a kind of record (morph alias).
     *
     * @return list<self>
     */
    public static function allowedFor(string $type): array
    {
        return match ($type) {
            'customer' => [self::IdFront, self::IdBack, self::Passport, self::LicenceFront, self::LicenceBack, self::InternationalPermit, self::Other],
            'rental' => [self::SignedContract, self::DepositProof, self::PaymentProof, self::Photo, self::Other],
            'vehicle' => [self::Photo, self::Other],
            'vehicle_document' => [self::Scan],
            'vehicle_damage' => [self::Photo, self::RepairInvoice],
            'maintenance_job' => [self::RepairInvoice, self::Photo, self::Other],
            default => [],
        };
    }

    /**
     * ID documents: the most sensitive files (law 09-08).
     */
    public function isIdDocument(): bool
    {
        return in_array($this, [self::IdFront, self::IdBack, self::Passport, self::LicenceFront, self::LicenceBack, self::InternationalPermit], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::IdFront => 'ID card (front)',
            self::IdBack => 'ID card (back)',
            self::Passport => 'Passport',
            self::LicenceFront => 'Driving licence (front)',
            self::LicenceBack => 'Driving licence (back)',
            self::InternationalPermit => 'International driving permit',
            self::SignedContract => 'Signed contract',
            self::DepositProof => 'Deposit proof',
            self::PaymentProof => 'Payment proof',
            self::Scan => 'Scan',
            self::RepairInvoice => 'Repair invoice',
            self::Photo => 'Photo',
            self::Other => 'Other',
        };
    }
}
