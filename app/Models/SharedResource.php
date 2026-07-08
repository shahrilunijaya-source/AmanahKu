<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A company account or tool shared by all staff (e.g. the company Gmail, Canva,
 * the WhatsApp number, the inhouse system). Every signed-in employee can view
 * these; only privileged roles maintain them (see SharedResourceController).
 *
 * The `password` is stored encrypted at rest via the `encrypted` cast — the DB
 * column holds ciphertext — even though the UI shows it in plaintext to staff.
 */
class SharedResource extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['password' => 'encrypted'];
    }
}
