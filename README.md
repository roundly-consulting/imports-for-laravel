<!-- roundly-badges:start -->
<p align="center">
  <a href="https://packagist.org/packages/roundly-consulting/imports-for-laravel"><img src="https://img.shields.io/packagist/v/roundly-consulting/imports-for-laravel?style=flat-square&label=release" alt="Latest release"></a>
  <a href="https://github.com/roundly-consulting/imports-for-laravel/actions/workflows/run-tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/imports-for-laravel/run-tests.yml?branch=main&style=flat-square&label=tests" alt="Tests"></a>
  <a href="https://github.com/roundly-consulting/imports-for-laravel/actions/workflows/fix-php-code-style-issues.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/imports-for-laravel/fix-php-code-style-issues.yml?branch=main&style=flat-square&label=code%20style" alt="Code style"></a>
  <a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/donate-support%20our%20open%20source-F24E29?style=flat-square&logo=stripe&logoColor=white" alt="Donate"></a>
  <a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/patreon-become%20a%20patron-F96854?style=flat-square&logo=patreon&logoColor=white" alt="Patreon"></a>
  <a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=imports-for-laravel#crypto"><img src="https://img.shields.io/badge/crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=flat-square&logo=bitcoin&logoColor=white" alt="Crypto"></a>
</p>
<!-- roundly-badges:end -->

# Imports for Laravel

CSV and XLSX imports into your Eloquent models: one importer class per record type, downloadable templates, column mapping, a validated preview, duplicate handling, background batches and undo. Nothing is saved until the user commits, and invalid rows never are.

## Installation

Requires PHP 8.4 (with the `zip`, `xmlreader`, `xmlwriter` and `mbstring` extensions) and Laravel 13.

```bash
composer require roundly-consulting/imports-for-laravel
php artisan vendor:publish --tag="options-migrations"   # saved column mappings live in options-for-laravel
php artisan vendor:publish --tag="imports-migrations"
php artisan make:queue-batches-table   # Laravel's job_batches table, if you do not have it yet
php artisan migrate
```

Schedule `imports:sweep` (every five minutes) and `imports:prune` (daily) so stuck imports are released and old files deleted.

## Usage

Describe one record type in an importer and list it in `config('imports.importers')`:

```php
use App\Models\Asset;
use App\Models\Vendor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Imports\Definition\{Field, NaturalKey, Ownership};
use RoundlyConsulting\Imports\Enums\{DuplicateMode, ImportOperation};
use RoundlyConsulting\Imports\Facades\Imports;
use RoundlyConsulting\Imports\Importer;
use RoundlyConsulting\Imports\Rows\{ImportContext, ImportRow};

final class AssetsImporter extends Importer
{
    public function key(): string { return 'assets'; }
    public function label(): string { return 'Assets'; }
    public function model(): string { return Asset::class; }
    public function ownership(): Ownership { return Ownership::column('team_id'); }
    public function naturalKey(): NaturalKey { return NaturalKey::by('asset_tag')->then('serial_number'); }

    public function fields(): array
    {
        return [
            Field::text('asset_tag')->aliases('tag', 'inventory no'),
            Field::text('serial_number'),
            Field::text('name')->required()->max(120),
            Field::date('purchased_on'),
            Field::reference('vendor')->to(Vendor::class)->by('vat_id', 'name'),
        ];
    }

    public function authorize(?Authenticatable $user, Model $owner, ImportOperation $operation): bool
    {
        return $user?->can('manage-assets', $owner) ?? false;
    }

    public function create(ImportRow $row, ImportContext $context): Model
    {
        return Asset::create([...$row->except('vendor'), 'vendor_id' => $row->reference('vendor')?->getKey(), ...$context->ownerAttributes()]);
    }

    public function update(Model $asset, ImportRow $row, ImportContext $context): Model
    {
        return tap($asset)->update($row->except('vendor'));
    }
}
```

Then upload, preview, commit — and undo if needed:

```php
$import = Imports::for($team)->by($user)
    ->upload('assets', $request->file('file'))   // CSV or XLSX; columns map from the headers
    ->mode(DuplicateMode::Update)
    ->preview();                                 // every row checked, nothing saved yet

$import->counts();                               // valid 47 · invalid 2 · duplicates 1
$batch = $import->commit();                      // a background job batch
$batch->rollback();                              // later: removes the rows it created, unless edited since
```

<!-- roundly-docs:start -->
## Documentation

The full documentation — configuration, every feature and its API, and testing — lives on our
website: **[roundly-consulting.com/open-source/docs/imports-for-laravel](https://roundly-consulting.com/open-source/docs/imports-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=imports-for-laravel)**

Release notes are in [CHANGELOG.md](CHANGELOG.md). To contribute, see the
[contributing guide](https://github.com/roundly-consulting/.github/blob/main/CONTRIBUTING.md).
<!-- roundly-docs:end -->

<!-- roundly-support:start -->
## Support our work

This package is free and open source, built and maintained by
[Roundly Consulting](https://roundly-consulting.com/open-source?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=imports-for-laravel).
If it saves you time, please consider supporting our open-source work — a one-time donation, a
monthly pledge on Patreon or a crypto donation helps fund maintenance, new features and new
packages.

<a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/Donate-Support%20Roundly%20open%20source-F24E29?style=for-the-badge&logo=stripe&logoColor=white" alt="Donate to Roundly open source"></a>
<a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/Patreon-Become%20a%20patron-F96854?style=for-the-badge&logo=patreon&logoColor=white" alt="Become a patron on Patreon"></a>
<a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=imports-for-laravel#crypto"><img src="https://img.shields.io/badge/Crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=for-the-badge&logo=bitcoin&logoColor=white" alt="Donate crypto: BTC, ETH, BNB or SOL"></a>
<!-- roundly-support:end -->

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
