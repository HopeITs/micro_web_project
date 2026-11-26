<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use App\Services\Staff\AuditLogService;
use Illuminate\Support\Facades\Lang;
use ReflectionClassConstant;
use Tests\TestCase;

/** No screen shows a code instead of words: every permission and every audit event is named in Arabic and English. */
final class TranslationCoverageTest extends TestCase
{
    public function test_every_permission_has_a_name_in_both_languages(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $missing = array_values(array_filter(AccountAccess::CAPABILITIES, fn (string $c) => ! Lang::hasForLocale('admin.cap.'.$c, $locale)));
            $this->assertSame([], $missing, "Permissions without a name ($locale)");
        }
    }

    public function test_every_audit_event_has_a_name_in_both_languages(): void
    {
        $events = array_keys((new ReflectionClassConstant(AccountAuditTrail::class, 'SUBJECTS'))->getValue());
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            app('translator')->setFallback($locale);
            $missing = array_values(array_filter($events, fn (string $e) => AuditLogService::label($e) === $e));
            $this->assertSame([], $missing, "Audit events without a name ($locale)");
        }
    }
}
