<?php

namespace App\Enums;

/**
 * Only individuals for now. Company customers (corporate leasing) come later:
 * company = who pays, drivers = individuals linked to the company (see CLAUDE.md).
 */
enum CustomerType: string
{
    case Individual = 'individual';
}
