<?php

namespace Tests\Unit\Communication;

use App\Services\Communication\EmailSimilarityService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class EmailSimilarityServiceTest extends TestCase
{
    protected EmailSimilarityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EmailSimilarityService();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public function test_computes_consistent_fingerprint_for_same_content(): void
    {
        $content = 'Test email content for fingerprinting';
        
        $fp1 = $this->service->computeFingerprint($content);
        $fp2 = $this->service->computeFingerprint($content);
        
        $this->assertEquals($fp1, $fp2);
        $this->assertEquals(16, strlen($fp1)); // 64 bits = 16 hex chars
    }

    public function test_detects_identical_content_as_similar(): void
    {
        $content = 'This is a test email content that should be detected as identical';
        
        $this->service->storeFingerprint($content);
        $result = $this->service->checkSimilarity($content);
        
        $this->assertTrue($result['is_similar']);
        $this->assertEquals(1.0, $result['similarity']);
    }

    public function test_detects_persian_hr_emails_as_similar(): void
    {
        // Email from requests/1/note.txt
        $email1 = <<<'EMAIL'
همکاران گرامی

با سلام و احترام،

      با توجه به ضرورت تکمیل و به‌روزرسانی سیستم اطلاعات منابع انسانی و لزوم انجام برخی اصلاحات و تغییرات در فرم اطلاعات کارکنان، خواهشمند است فرم جدید پیوست را جایگزین 
      
فرم قبلی نموده و نسبت به تکمیل دقیق بخش‌های مرتبط با اطلاعات خود اقدام فرمایید.

لطفاً فرم تکمیل‌شده را حداکثر تا تاریخ 1405/09/05 به مدیریت منابع انسانی عودت دهید.

با عنایت به تأکید مدیرعامل محترم و فوریت موضوع، همچنین اهمیت اطلاعات مذکور در برنامه‌ریزی و مدیریت اثربخش منابع انسانی، انتظار می‌رود در تکمیل و ارسال به‌موقع فرم؛ همکاری و اهتمام لازم را مبذول فرمایید. شایان ذکر است Sheet های مربوط به شماره های 8- 9-10-11-14-15 ، متعاقباً بر حسب سوابق عملکردی کارکنان توسط همکاران منابع انسانی تنظیم و تکمیل خواهد شد.

پیشاپیش از همکاری و همراهی شما همکاران گرامی سپاسگزاریم.

                                                                                   مدیریت منابع انسانی شرکت مپصا
EMAIL;

        // Email from requests/2/note.txt
        $email2 = <<<'EMAIL'
همکاران گرامی

با سلام و احترام،

با تأکید مدیرعامل محترم و در راستای تکمیل، ساماندهی و به‌روزرسانی سیستم اطلاعات منابع انسانی و ایجاد بانک اطلاعاتی جامع و دقیق از مشخصات فردی، خانوادگی، تحصیلی و سوابق شغلی کارکنان،

ضروری است کلیه همکاران نسبت به تکمیل دقیق و کامل فرم اطلاعات پرسنلی پیوست و ارائه مدارک و مستندات موردنیاز به شرح زیر اقدام نمایند:

1.      رزومه کاری به‌روز

2.      عکس پرسنلی

3.      تصویر تمامی صفحات شناسنامه

4.      تصویر کارت ملی

5.      تصویر کارت پایان خدمت یا معافیت (حسب مورد)

6.      تصویر کلیه مدارک تحصیلی

7.      کد رهگیری مدارک تحصیلی

8.      سوابق بیمه

9.      شماره حساب بانکی

10.  گواهی کد پستی محل سکونت

11.  تصویر گواهینامه‌ها و مدارک دوره‌های آموزشی

12.  مدارک هویتی همسر و اعضای خانواده (حسب مورد)

13.  نمونه امضا

نحوه دسته‌بندی و ارسال مدارک:

تأکید می‌گردد کلیه مدارک و مستندات درخواستی باید دقیقاً مطابق ساختار پوشه‌بندی نمونه ارسالی، به‌صورت مجزا و با عناوین مشخص‌شده دسته‌بندی و ذخیره شوند.

باین منظور، لازم است برای هر یک از مدارک، پوشه‌ای مستقل مطابق شماره‌ گذاری و عنوان درج‌شده در نمونه ایجاد و فایل مربوطه در پوشه اختصاصی خود قرار گیرد.

در نهایت، تمامی پوشه‌ها باید در قالب یک فایل فشرده (ZIP) تجمیع و ارسال شوند.

رعایت دقیق ساختار پوشه ‌بندی، شماره ‌گذاری و عناوین تعیین ‌شده در نمونه ارسالی الزامی است

از ارسال مدارک به ‌صورت پراکنده، عکس بی کیفیت و بدون دسته‌ بندی یا با ساختاری مغایر با نمونه ارسالی خودداری فرمایید.

خواهشمند است ضمن مطالعه دقیق و تکمیل تمامی بخش‌های فرم اطلاعات پرسنلی و اطمینان از صحت و کامل بودن اطلاعات درج‌ شده،

فایل فشرده (ZIP) حاوی فرم تکمیل ‌شده و کلیه مدارک و مستندات را حداکثر تا تاریخ 04/09/1405  به مدیریت منابع انسانی تحویل نمایید.

بدیهی است همکاری به ‌موقع و دقیق تمامی همکاران در این زمینه، نقش بسزایی در تکمیل و به‌ روزرسانی اطلاعات پرسنلی، ساماندهی پرونده‌های کارکنان و تسهیل و تسریع در ارائه خدمات و انجام امور اداری و منابع انسانی خواهد داشت.

پیشاپیش از همکاری، دقت نظر و همراهی ارزشمند شما سپاسگزاریم.

با تجدید احترام

واحد منابع انسانی شرکت مپصا
EMAIL;

        // Store first email
        $this->service->storeFingerprint($email1);
        
        // Check second email - should be detected as similar (both are HR emails with same structure)
        $result = $this->service->checkSimilarity($email2, ['threshold' => 0.55]);
        
        $this->assertTrue($result['is_similar'], 
            "Expected emails to be similar. Similarity: {$result['similarity']}");
        $this->assertGreaterThan(0.55, $result['similarity']);
    }

    public function test_normalizes_dates_and_numbers(): void
    {
        $content1 = 'Please submit by 2024/01/15 or 1403/10/20';
        $content2 = 'Please submit by 2025/06/30 or 1404/05/10';
        
        $fp1 = $this->service->computeFingerprint($content1);
        $fp2 = $this->service->computeFingerprint($content2);
        
        // After normalization, both should have [DATE] placeholders and be identical
        $this->assertEquals($fp1, $fp2);
    }

    public function test_normalizes_email_addresses(): void
    {
        $content1 = 'Contact us at user1@example.com for details';
        $content2 = 'Contact us at user2@domain.org for details';
        
        $fp1 = $this->service->computeFingerprint($content1);
        $fp2 = $this->service->computeFingerprint($content2);
        
        $this->assertEquals($fp1, $fp2);
    }

    public function test_normalizes_persian_numbers(): void
    {
        $content1 = 'فرم شماره ۱۲۳۴۵ را تکمیل کنید';
        $content2 = 'فرم شماره ۶۷۸۹۰ را تکمیل کنید';
        
        $fp1 = $this->service->computeFingerprint($content1);
        $fp2 = $this->service->computeFingerprint($content2);
        
        $this->assertEquals($fp1, $fp2);
    }

    public function test_detects_different_content_as_not_similar(): void
    {
        $email1 = 'Dear user, welcome to our platform. Please verify your account by clicking the link.';
        $email2 = 'Your order number twelve thousand three hundred forty five has been shipped and will arrive tomorrow morning.';
        
        $this->service->storeFingerprint($email1);
        $result = $this->service->checkSimilarity($email2, ['threshold' => 0.7]);
        
        $this->assertFalse($result['is_similar']);
        $this->assertLessThan(0.7, $result['similarity']);
    }

    public function test_stores_and_retrieves_metadata(): void
    {
        $content = 'Test email with metadata';
        $metadata = [
            'recipient' => 'user@example.com',
            'subject' => 'Test Subject',
            'campaign_id' => 'camp_123',
        ];
        
        $fingerprint = $this->service->storeFingerprint($content, $metadata);
        
        $key = 'email:similarity:' . $fingerprint;
        $stored = Cache::get($key);
        
        $this->assertNotNull($stored);
        $this->assertEquals($metadata, $stored['metadata']);
        $this->assertEquals($content, $stored['content']);
    }

    public function test_clears_all_fingerprints(): void
    {
        $this->service->storeFingerprint('First unique email content here');
        $this->service->storeFingerprint('Second completely different email content there');
        
        $statsBefore = $this->service->getStats();
        $this->assertEquals(2, $statsBefore['total_fingerprints']);
        
        $this->service->clearFingerprints();
        
        $statsAfter = $this->service->getStats();
        $this->assertEquals(0, $statsAfter['total_fingerprints']);
    }

    public function test_returns_stats(): void
    {
        $stats = $this->service->getStats();
        
        $this->assertArrayHasKey('total_fingerprints', $stats);
        $this->assertArrayHasKey('cache_ttl_seconds', $stats);
        $this->assertArrayHasKey('hash_bits', $stats);
        $this->assertArrayHasKey('default_threshold', $stats);
        $this->assertEquals(64, $stats['hash_bits']);
        $this->assertEquals(0.85, $stats['default_threshold']);
    }

    public function test_handles_custom_threshold(): void
    {
        $email1 = 'This is a test email content for threshold testing purposes';
        $email2 = 'This is a test email content for threshold test only'; // Different ending
        
        $this->service->storeFingerprint($email1);
        
        // With high threshold - should not match (very different endings)
        $resultHigh = $this->service->checkSimilarity($email2, ['threshold' => 0.95]);
        $this->assertFalse($resultHigh['is_similar']);
        
        // With low threshold - should match (similar beginnings)
        $resultLow = $this->service->checkSimilarity($email2, ['threshold' => 0.7]);
        $this->assertTrue($resultLow['is_similar']);
    }

    public function test_normalizes_persian_names_and_honorifics(): void
    {
        $content1 = 'آقای محمد رضایی محترم، لطفاً فرم را تکمیل کنید';
        $content2 = 'جناب آقای علی احمدی محترم، لطفاً فرم را تکمیل کنید';
        
        $fp1 = $this->service->computeFingerprint($content1);
        $fp2 = $this->service->computeFingerprint($content2);
        
        // Names should be normalized to [NAME]
        $this->assertEquals($fp1, $fp2);
    }

    public function test_normalizes_reference_numbers(): void
    {
        $content1 = 'مرجع: ۱۲۳۴۵۶، کد پیگیری: ABC-123';
        $content2 = 'مرجع: ۹۸۷۶۵۴، کد پیگیری: XYZ-789';
        
        $fp1 = $this->service->computeFingerprint($content1);
        $fp2 = $this->service->computeFingerprint($content2);
        
        $this->assertEquals($fp1, $fp2);
    }
}