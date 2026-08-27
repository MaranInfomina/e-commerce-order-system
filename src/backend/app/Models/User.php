<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    public const ROLE_CUSTOMER = 'customer';

    public const ROLE_ADMIN = 'admin';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    // NFR-10: password never reaches a response, whatever a future resource
    // or dd() does. Hiding it on the model is the backstop; UserResource is
    // the primary control.
    /** @var list<string> */
    protected $hidden = [
        'password',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Laravel hashes on assignment, so a plain-text password
            // assigned to this attribute is stored as bcrypt.
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }
}
