#!/bin/bash
set -e
# Fresh SQLite DB: all migrations, demo seeders, mock suppliers, and
# ₹5,00,000 in every demo user's wallet.
cd "${BACKEND_DIR:?}"
export DB_CONNECTION=sqlite DB_DATABASE="${E2E_DB:?}"
rm -f "$E2E_DB" && touch "$E2E_DB"
php artisan cache:clear > /dev/null 2>&1 || true
php artisan migrate:fresh --seed --force > /dev/null
for s in HotelSupplierSeeder BusSupplierSeeder CabSupplierSeeder CruiseSupplierSeeder ActivitySupplierSeeder InsuranceSupplierSeeder HolidaySamplePackageSeeder VisaSampleProductSeeder; do php artisan db:seed --class=$s --force > /dev/null; done
php artisan tinker --execute='$s=app(App\Domains\Wallet\Services\WalletService::class); foreach(App\Models\User::all() as $u){ $w=App\Domains\Wallet\Models\Wallet::firstOrCreate(["tenant_id"=>$u->tenant_id,"owner_type"=>"user","owner_id"=>$u->id]); $s->credit($w,"500000.00",App\Domains\Wallet\Models\WalletLedgerEntry::REF_TOPUP);} echo "db ready\n";'
