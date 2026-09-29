<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An admin-editable page (Terms of Service, About) — Site → Pages. The
 * body is cleaned of anything unsafe when saved and again when shown,
 * the same way tutorials are.
 */
class SitePage extends Model
{
    /** The pages the site links to; admins edit these, never add or delete. */
    public const SLUGS = ['terms' => '/terms', 'about' => '/about'];

    protected $fillable = ['slug', 'title', 'summary', 'body'];

    public function setBodyAttribute(?string $value): void
    {
        $this->attributes['body'] = Tutorial::clean($value);
    }

    /**
     * The body ready to show: cleaned, with {support_email_sentence}
     * replaced by ", or by email at …" when a support email is set.
     */
    public function renderedBody(): string
    {
        $email = (string) config('site.support_email');
        $sentence = filter_var($email, FILTER_VALIDATE_EMAIL)
            ? ', or by email at <a href="mailto:'.e($email).'">'.e($email).'</a>'
            : '';

        return str_replace('{support_email_sentence}', $sentence, Tutorial::clean($this->body));
    }

    public function path(): string
    {
        return self::SLUGS[$this->slug] ?? '/'.$this->slug;
    }
}
