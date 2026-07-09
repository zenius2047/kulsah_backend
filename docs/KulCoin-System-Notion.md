# KulCoin System

**Product:** Kulsah Social Platform  
**Currency:** KulCoin (KC)  
**Version:** 1.0  
**Status:** Implemented in backend

---

## 1. Overview

KulCoin is Kulsah's in-app virtual currency. It is used for creator support, gifts, voting, and future premium interactions inside the platform.

KulCoin is not a cryptocurrency, is not blockchain-based, and cannot be withdrawn as cash or transferred directly between users.

The system is designed to be:
- Ledger-based
- Auditable
- Idempotent
- Reversible for support/admin workflows
- Separate from the existing USD wallet system

---

## 2. Product Goals

KulCoin powers the creator economy by allowing users to:
- Buy coin packages with real money
- Send digital gifts to creators
- Vote in contests and challenges
- Receive promotional or bonus coins
- Participate in future premium experiences

---

## 3. Currency Rules

| Property | Rule |
| --- | --- |
| Currency name | KulCoin |
| Symbol | KC |
| Base value | 1 KC = $0.01 USD |
| Blockchain | No |
| Withdrawable | No |
| Tradable | No |
| Direct user-to-user transfer | No |
| Transfer through gifts | Yes, indirectly |
| Expiration | Purchased KC does not expire |

---

## 4. Current Implementation Strategy

KulCoin is implemented as a separate subsystem from the current USD wallet.

### Why separate?
The existing wallet system is USD-oriented and already supports:
- creator payments
- pending balances
- settlement
- ledger entries

KulCoin needs its own rules and balances:
- KC available balance
- KC bonus balance
- KC transaction history
- KC gift/vote spending

This avoids mixing fiat and in-app currency logic.

---

## 5. Wallet Design

Every user gets a KulCoin wallet automatically.

### KC Wallet Fields
- Available KC
- Bonus KC
- Total KC
- Status
- Last ledger timestamp

### Balance Buckets
- `available`: coins bought or credited normally
- `bonus`: promotional or reward coins

### Spending Rule
When spending, the system consumes:
1. Available KC first
2. Bonus KC second

This keeps promotional coins usable without changing the core purchase balance.

---

## 6. Ledger Model

Every KC movement creates an immutable transaction and one or more ledger entries.

### Transaction Types
- `purchase`
- `gift`
- `vote`
- `bonus`
- future: `refund`, `chargeback`, `admin_adjustment`, `promotion`

### Ledger Principles
- Every transaction is atomic
- Every transaction is auditable
- Every transaction can be traced by reference
- Idempotency keys prevent duplicate processing

---

## 7. Coin Packages

KulCoin packages are pre-defined and seeded into the system.

### Example Packages
| Package | KC | USD |
| --- | ---: | ---: |
| Starter Pack | 50 | 0.50 |
| Lite Pack | 100 | 1.00 |
| Creator Pack | 200 | 2.00 |
| Boost Pack | 500 | 5.00 |
| Pro Pack | 1,000 | 10.00 |
| Elite Pack | 2,000 | 20.00 |
| Mega Pack | 3,000 | 30.00 |
| Ultra Pack | 4,000 | 40.00 |
| Champion Pack | 5,000 | 50.00 |
| Max Pack | 5,999 | 100.00 |

### Business Rules
- Minimum package purchase: 50 KC
- Maximum standard package: 5,999 KC
- Bonus coins may be attached to some packages

---

## 8. Gift Catalog

Gift items are also seeded and configurable by admin.

### Example Gifts
| Gift | Cost |
| --- | ---: |
| Rose | 50 KC |
| Heart | 100 KC |
| Fire | 200 KC |
| Trophy | 500 KC |
| Crown | 1,000 KC |
| Diamond | 2,000 KC |
| Super Star | 5,999 KC |

### Gift Rules
- Gifts deduct KC from the sender
- Creator earnings are recorded separately
- Gift prices can be changed by admin

---

## 9. User Flows

### A. Purchase KC
1. User selects a coin package
2. User chooses payment method
3. Payment is processed
4. KC is credited to the user wallet
5. Transaction is written to the ledger

### B. Send Gift
1. User selects a gift
2. System validates balance
3. KC is deducted
4. Gift transaction is recorded
5. Creator earnings are generated
6. Ledger is updated

### C. Vote in Challenge
1. User selects contest/creator
2. User chooses vote count
3. System calculates KC cost
4. KC is deducted
5. Vote transaction is recorded
6. Leaderboard or challenge totals can be updated downstream

### D. Bonus Coins
1. Admin selects a user
2. Admin enters bonus amount
3. System credits bonus KC
4. Transaction is logged

---

## 10. Backend Architecture

### Main Components
- `KulCoinWallet`
- `KulCoinPackage`
- `KulCoinGift`
- `KulCoinTransaction`
- `KulCoinLedgerEntry`
- `KulCoinService`
- `KulCoinController`

### Responsibilities
- `KulCoinService`
  - business logic
  - balance updates
  - transaction creation
  - ledger consistency

- `KulCoinController`
  - request validation
  - API responses
  - error handling

- Models
  - represent wallets, packages, gifts, transactions, and ledger entries

---

## 11. API Endpoints

All endpoints are under the authenticated general routes.

### Wallet
- `GET /api/v1/general/kulcoin/wallet`
- Returns wallet balances and status

### Ledger
- `GET /api/v1/general/kulcoin/ledger`
- Returns paginated transaction ledger

### Packages
- `GET /api/v1/general/kulcoin/packages`
- Returns active KC packages

### Gifts
- `GET /api/v1/general/kulcoin/gifts`
- Returns active gifts

### Purchase
- `POST /api/v1/general/kulcoin/purchase`
- Credits KC after a successful payment flow

### Send Gift
- `POST /api/v1/general/kulcoin/gifts/send`
- Deducts KC and records gift transaction

### Vote
- `POST /api/v1/general/kulcoin/votes`
- Deducts KC for challenge voting

### Bonus
- `POST /api/v1/general/kulcoin/bonus`
- Admin-only bonus coin issuance

---

## 12. Data Model

### `kulcoin_wallets`
- `user_id`
- `account_key`
- `account_name`
- `currency_code`
- `available_balance_kc`
- `bonus_balance_kc`
- `status`
- `last_ledger_at`

### `kulcoin_packages`
- `code`
- `name`
- `coin_amount`
- `bonus_coin_amount`
- `usd_price`
- `currency_code`
- `is_active`
- `sort_order`
- `metadata`

### `kulcoin_gifts`
- `code`
- `name`
- `coin_cost`
- `is_active`
- `icon_url`
- `animation_url`
- `metadata`

### `kulcoin_transactions`
- `reference`
- `idempotency_key`
- `type`
- `status`
- `user_id`
- `counterparty_wallet_id`
- `package_id`
- `gift_id`
- `local_currency`
- `local_amount`
- `usd_amount`
- `coin_amount`
- `bonus_coin_amount`
- `net_coin_amount`
- `description`
- `metadata`
- `performed_by_user_id`
- `processed_at`

### `kulcoin_ledger_entries`
- `kulcoin_transaction_id`
- `kulcoin_wallet_id`
- `entry_type`
- `balance_bucket`
- `amount_kc`
- `running_balance_kc`
- `narration`
- `metadata`
- `settlement_available_at`
- `settled_at`

---

## 13. Creator Earnings

When a user sends a gift, the platform converts the gift into creator earnings in USD using the configured share percentage.

### Example
- Gift value: 50 KC
- KC to USD rate: $0.01
- Creator share: 70%
- Creator earnings: $0.35 pending USD

### Notes
- Creators do not receive KC directly
- Earnings are handled through the existing USD wallet system
- This keeps the current payout infrastructure reusable

---

## 14. Configuration

### `config/kulcoin.php`
- Currency code
- Coin to USD rate
- Creator share percentage
- Vote price
- Issuer wallet key
- Treasury wallet key
- Promo wallet key
- Wallet status

### Key Config Values
- `KULCOIN_COIN_TO_USD_RATE`
- `KULCOIN_CREATOR_SHARE_PERCENT`
- `KULCOIN_VOTE_COIN_PRICE`
- `KULCOIN_ISSUER_ACCOUNT_KEY`
- `KULCOIN_TREASURY_ACCOUNT_KEY`
- `KULCOIN_PROMO_ACCOUNT_KEY`

---

## 15. Security and Integrity

### Protections
- Atomic DB transactions
- Ledger-based accounting
- Idempotency support for repeated requests
- Wallet balance checks before debit
- Admin-only bonus issuance
- Role-protected endpoints

### Required Audit Fields
- Transaction reference
- Wallet IDs
- Amounts
- Balance before/after
- Actor
- Timestamp
- Optional device/IP metadata

---

## 16. Admin Capabilities

Admins can:
- View wallets and transactions
- Issue bonus coins
- Manage package and gift catalogs
- Disable packages or gifts
- Extend the system with promotions
- Later: reverse or refund transactions

---

## 17. Future Extensions

The current structure supports future expansion into:
- livestream tipping
- subscription payments in KC
- premium stickers
- fan clubs
- event tickets
- marketplace purchases
- reward campaigns
- referral bonuses
- daily login rewards

---

## 18. Rollout Notes

### Already implemented
- KC wallet tables
- KC catalog seeding
- purchase endpoint
- gift endpoint
- vote endpoint
- bonus endpoint
- ledger resources
- feature tests

### Recommended next steps
1. Connect the frontend wallet UI to `/kulcoin/wallet`
2. Add the buy-coins flow in the app
3. Add gift picker UI
4. Add challenge voting UI
5. Add refunds/admin reversal workflows
6. Add analytics dashboard for coin movement

---

## 19. Summary

KulCoin is Kulsah's in-app currency layer for gifts, votes, and future premium engagement. It is fully ledger-based, separate from USD wallet operations, and built to support secure purchases, promotions, and creator monetization.

The backend now has the foundation needed to scale KulCoin into a full creator economy system.
