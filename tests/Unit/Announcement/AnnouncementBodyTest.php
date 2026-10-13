<?php

namespace Tests\Unit\Announcement;

use App\Domains\Announcement\Domain\AnnouncementBody;
use Tests\TestCase;

/** Spec 2026-10-13, §3.4 : le seul endroit où le Markdown devient du HTML. */
class AnnouncementBodyTest extends TestCase
{
    public function test_formatting_is_rendered(): void
    {
        $html = AnnouncementBody::toHtml("## Consigne\n\n**Casque** obligatoire.\n\n- un\n- deux\n\n[Règlement](https://chictuktuk.com/r)");

        $this->assertStringContainsString('<h2>Consigne</h2>', $html);
        $this->assertStringContainsString('<strong>Casque</strong>', $html);
        $this->assertStringContainsString('<li>un</li>', $html);
        $this->assertStringContainsString('href="https://chictuktuk.com/r"', $html);
    }

    public function test_raw_html_is_stripped(): void
    {
        $html = AnnouncementBody::toHtml('Bonjour <script>alert(1)</script> <img src=x onerror=alert(1)>');

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_unsafe_links_are_neutralised(): void
    {
        $html = AnnouncementBody::toHtml('[clic](javascript:alert(1)) et [data](data:text/html;base64,AAAA)');

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('data:text/html', $html);
    }
}
