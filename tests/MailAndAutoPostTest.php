<?php

namespace Vdu\TisLogging\Tests;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Vdu\TisLogging\Http\Middleware\LogPageViews;
use Vdu\TisLogging\Tests\Fixtures\TestArticle;

class MailAndAutoPostTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('test_articles', function ($table) {
            $table->increments('id');
            $table->string('title');
            $table->string('secret_code')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('test_articles');
        parent::tearDown();
    }

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        // Realus (nors ir niekur nesiunčiantis) mailer'is - Mail::fake()
        // NEsukelia MessageSent event'o, tad su juo listener'io patikrinti
        // neįmanoma.
        $app['config']->set('mail.default', 'array');
        $app['config']->set('mail.driver', 'array');
        $app['config']->set('mail.mailers.array', ['transport' => 'array']);
    }

    protected function htmlResponse(): Response
    {
        $response = new Response('<html>ok</html>');
        $response->headers->set('Content-Type', 'text/html; charset=UTF-8');

        return $response;
    }

    protected function passThrough(Request $request, ?callable $duringRequest = null)
    {
        $middleware = new LogPageViews();
        $response = $this->htmlResponse();

        return $middleware->handle($request, function () use ($response, $duringRequest) {
            if ($duringRequest) {
                $duringRequest();
            }

            return $response;
        });
    }

    /** @test */
    public function it_logs_sent_mail_with_all_recipients()
    {
        Mail::raw('Testinis turinys', function ($message) {
            $message->to('jonas@vdu.lt')
                ->cc('petras@vdu.lt')
                ->subject('Naujienlaiškis');
        });

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('mail_sent', $decoded['context']['category']);
        $this->assertContains('jonas@vdu.lt', $decoded['context']['context']['to']);
        $this->assertContains('petras@vdu.lt', $decoded['context']['context']['cc']);
        $this->assertSame('Naujienlaiškis', $decoded['context']['context']['subject']);
        $this->assertSame(2, $decoded['context']['context']['recipients_total']);
    }

    /** @test */
    public function mail_logging_can_be_disabled()
    {
        config(['audit.mail.enabled' => false]);

        Mail::raw('Turinys', function ($message) {
            $message->to('jonas@vdu.lt')->subject('Tema');
        });

        $this->assertNull($this->findLogFile('audit'));
    }

    /** @test */
    public function recipient_list_can_be_capped()
    {
        config(['audit.mail.max_recipients' => 2]);

        Mail::raw('Turinys', function ($message) {
            $message->to(['a@vdu.lt', 'b@vdu.lt', 'c@vdu.lt', 'd@vdu.lt'])
                ->subject('Masinis');
        });

        $decoded = $this->lastLogEntry('audit');
        $to = $decoded['context']['context']['to'];

        $this->assertCount(3, $to);   // 2 adresai + žyma apie likusius
        $this->assertStringContainsString('ir dar 2', end($to));
    }

    /** @test */
    public function auto_mode_logs_a_post_that_changed_nothing()
    {
        config([
            'audit.log_page_views.mode' => 'all',
            'audit.log_page_views.post_mode' => 'auto',
        ]);

        // Peržiūra su filtrais - jokių DB pakeitimų.
        $this->passThrough(Request::create('/user/personal_studies', 'POST'));

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('view', $decoded['context']['category']);
        $this->assertStringContainsString('user/personal_studies', $decoded['message']);
    }

    /** @test */
    public function auto_mode_does_not_log_a_post_that_saved_data()
    {
        config([
            'audit.log_page_views.mode' => 'all',
            'audit.log_page_views.post_mode' => 'auto',
        ]);

        // Užklausos metu išsaugomas modelis - tai veiksmas, ne peržiūra,
        // tad papildomo "view" įrašo būti neturi.
        $this->passThrough(Request::create('/admin/save_news', 'POST'), function () {
            TestArticle::create(['title' => 'Naujas įrašas']);
        });

        $file = $this->findLogFile('audit');
        $content = file_get_contents($file);

        $this->assertStringContainsString('"category":"create"', $content);
        $this->assertStringNotContainsString('"category":"view"', $content);
    }

    /** @test */
    public function auto_mode_does_not_log_a_post_that_only_sent_mail()
    {
        config([
            'audit.log_page_views.mode' => 'all',
            'audit.log_page_views.post_mode' => 'auto',
        ]);

        // Laiško išsiuntimas yra reikšmingas veiksmas (fiksuojamas atskirai),
        // tad POST neturi būti papildomai pažymėtas kaip peržiūra.
        $this->passThrough(Request::create('/admin/send', 'POST'), function () {
            Mail::raw('Naujienlaiškis', function ($message) {
                $message->to('jonas@vdu.lt')->subject('Tema');
            });
        });

        $content = file_get_contents($this->findLogFile('audit'));

        $this->assertStringContainsString('"category":"mail_sent"', $content);
        $this->assertStringNotContainsString('"category":"view"', $content);
    }

    /** @test */
    public function whitelist_post_mode_ignores_posts_without_explicit_listing()
    {
        config([
            'audit.log_page_views.mode' => 'all',
            'audit.log_page_views.post_mode' => 'whitelist',
            'audit.log_page_views.post_routes' => [],
        ]);

        $this->passThrough(Request::create('/user/personal_studies', 'POST'));

        $this->assertNull($this->findLogFile('audit'));
    }

    /** @test */
    public function post_routes_still_win_in_auto_mode()
    {
        config([
            'audit.log_page_views.mode' => 'all',
            'audit.log_page_views.post_mode' => 'auto',
            'audit.log_page_views.post_routes' => ['admin/save_news'],
        ]);

        // Eksplicitiškai išvardintas maršrutas fiksuojamas net jei
        // užklausos metu buvo pakeitimų - tai leidžia sąmoningai
        // perrašyti automatinį sprendimą.
        $this->passThrough(Request::create('/admin/save_news', 'POST'), function () {
            TestArticle::create(['title' => 'Įrašas']);
        });

        $content = file_get_contents($this->findLogFile('audit'));

        $this->assertStringContainsString('"category":"view"', $content);
    }

    /** @test */
    public function mails_beyond_the_limit_are_merged_into_one_summary()
    {
        config(['audit.mail.max_individual_per_request' => 3]);

        // Imituojame naujienlaiskio cikla: 8 gavejai, kiekvienas atskiras laiskas.
        foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'] as $letter) {
            Mail::raw('Turinys', function ($message) use ($letter) {
                $message->to("{$letter}@vdu.lt")->subject('Naujienlaiskis');
            });
        }

        app(\Vdu\TisLogging\Support\MailBatchTracker::class)->flush();

        $lines = array_values(array_filter(explode("\n", trim(
            file_get_contents($this->findLogFile('audit'))
        ))));

        // 3 atskiri irasai + 1 suvestine = 4, o ne 8.
        $this->assertCount(4, $lines);

        $summary = json_decode(end($lines), true);

        $this->assertTrue($summary['context']['context']['summary']);
        $this->assertSame(5, $summary['context']['context']['recipients_total']);
        $this->assertContains('d@vdu.lt', $summary['context']['context']['to']);
        $this->assertContains('h@vdu.lt', $summary['context']['context']['to']);
    }

    /** @test */
    public function individual_entries_before_the_limit_keep_full_detail()
    {
        config(['audit.mail.max_individual_per_request' => 2]);

        foreach (['pirmas', 'antras', 'trecias'] as $name) {
            Mail::raw('Turinys', function ($message) use ($name) {
                $message->to("{$name}@vdu.lt")->subject('Tema');
            });
        }

        $lines = array_values(array_filter(explode("\n", trim(
            file_get_contents($this->findLogFile('audit'))
        ))));

        $first = json_decode($lines[0], true);

        $this->assertContains('pirmas@vdu.lt', $first['context']['context']['to']);
        $this->assertArrayNotHasKey('summary', $first['context']['context']);
    }

    /** @test */
    public function summaries_are_grouped_by_subject()
    {
        config(['audit.mail.max_individual_per_request' => 1]);

        Mail::raw('A', function ($m) { $m->to('x@vdu.lt')->subject('Pirma tema'); });
        Mail::raw('B', function ($m) { $m->to('y@vdu.lt')->subject('Pirma tema'); });
        Mail::raw('C', function ($m) { $m->to('z@vdu.lt')->subject('Antra tema'); });

        app(\Vdu\TisLogging\Support\MailBatchTracker::class)->flush();

        $content = file_get_contents($this->findLogFile('audit'));

        $this->assertStringContainsString('Pirma tema', $content);
        $this->assertStringContainsString('Antra tema', $content);
        // Dvi atskiros suvestines - po vienai temai.
        $this->assertSame(2, substr_count($content, '"summary":true'));
    }

    /** @test */
    public function zero_limit_means_every_mail_is_logged_separately()
    {
        config(['audit.mail.max_individual_per_request' => 0]);

        foreach (['a', 'b', 'c'] as $letter) {
            Mail::raw('Turinys', function ($message) use ($letter) {
                $message->to("{$letter}@vdu.lt")->subject('Tema');
            });
        }

        $content = file_get_contents($this->findLogFile('audit'));

        $this->assertSame(3, substr_count($content, '"category":"mail_sent"'));
        $this->assertStringNotContainsString('"summary":true', $content);
    }
}
