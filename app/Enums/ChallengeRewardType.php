<?php

namespace App\Enums;

enum ChallengeRewardType: string
{
    case Cash = 'cash';
    case WalletCredit = 'wallet_credit';
    case PhysicalProduct = 'physical_product';
    case Feature = 'feature';
    case Badge = 'badge';
    case Subscription = 'subscription';
    case Voucher = 'voucher';
    case Experience = 'experience';
    case Custom = 'custom';
}
