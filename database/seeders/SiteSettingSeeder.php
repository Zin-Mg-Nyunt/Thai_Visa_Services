<?php

namespace Database\Seeders;

use App\Actions\Site\IdentifyWebsiteImage;
use App\Actions\Site\SiteSettings;
use App\Models\SiteSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Development-only sample content for the public website, so the landing page shows every section on a fresh local database
 * before any admin has written the real content (HLD Section 4.10: "mockup data may be used temporarily during development").
 *
 * - It runs only when APP_ENV is `local`. In production, staging and the tests it does nothing, so sample text can never be
 *   shown there as the agency's real facts.
 * - It fills every admin-written text in English and Burmese, gives clearly fictional contact details (an example.com email and
 *   links, a 000 phone number, an address marked as a sample), stores a logo and a favicon (the app's own icon) on the website
 *   images disk, and publishes the website, which needs the logo and the favicon (Section 4.11).
 * - It invents no business facts: no agency name (the app name is shown), no years, licences, success rates or numbers.
 * - It leaves the dynamic parts alone: the services come from the catalogue and the statistics from real records; no statistic
 *   is approved here, so the statistics section stays as it was.
 * - It only creates the row. When the row already exists (an admin has saved something), nothing is changed, so running it
 *   again never overwrites the admin's content or publish choice. Everything it sets can be edited in Settings as usual.
 */
class SiteSettingSeeder extends Seeder
{
    /** The image used for the sample logo and favicon: the app's own icon, a PNG. */
    private const SAMPLE_IMAGE = 'apple-touch-icon.png';

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('SiteSettingSeeder: skipped, sample website content is seeded only when APP_ENV=local.');

            return;
        }

        if (SiteSetting::query()->whereKey(SiteSetting::ID)->exists()) {
            return;
        }

        $disk = Storage::disk(SiteSetting::DISK);
        $image = (string) file_get_contents(public_path(self::SAMPLE_IMAGE));
        $keys = [];

        foreach (['logo_key', 'favicon_key'] as $column) {
            $key = IdentifyWebsiteImage::DIRECTORY.'/'.Str::uuid().'.png';

            if ($disk->put($key, $image) === false) {
                throw new \RuntimeException("SiteSettingSeeder: the sample {$column} could not be stored.");
            }

            $keys[$column] = $key;
        }

        DB::transaction(function () use ($keys): void {
            $setting = new SiteSetting(self::content());
            $setting->forceFill(['id' => SiteSetting::ID, ...$keys, 'is_published' => true])->save();
        });

        // The seeders run without model events, so the cached settings are cleared here.
        Cache::forget(SiteSettings::CACHE_KEY);
    }

    /**
     * The sample texts and contact details, within the limits of the admin forms.
     *
     * @return array<string, mixed>
     */
    public static function content(): array
    {
        return [
            'hero_heading' => 'Thai visas and work permits, handled with care',
            'hero_heading_my' => 'ထိုင်းဗီဇာနှင့် အလုပ်လုပ်ခွင့် ကိစ္စများကို ဂရုတစိုက် ဆောင်ရွက်ပေးပါသည်',
            'hero_description' => 'We help Myanmar nationals living and working in Thailand with passports, CI, Pink Cards, work permits and '
                .'90-day reports, in Burmese, with the fee agreed before we start and regular updates at every step.',
            'hero_description_my' => 'ထိုင်းနိုင်ငံတွင် နေထိုင်လုပ်ကိုင်နေသော မြန်မာနိုင်ငံသားများအတွက် ပတ်စပို့၊ CI၊ Pink Card၊ အလုပ်လုပ်ခွင့်နှင့် '
                .'၉၀ ရက် အစီရင်ခံခြင်း ကိစ္စများကို မြန်မာဘာသာဖြင့် ကူညီဆောင်ရွက်ပေးပါသည်။ မစတင်မီ ကုန်ကျစရိတ်ကို သဘောတူညီပြီး အဆင့်တိုင်းကို ပုံမှန် အသိပေးပါသည်။',

            'overview' => 'We are a visa and document service for Myanmar workers and families in Thailand. We explain what each application '
                .'needs, prepare and check the documents with you, and follow your case with the Thai offices until it is finished. '
                .'You can follow the progress of your service online with your own tracking link.',
            'overview_my' => 'ကျွန်ုပ်တို့သည် ထိုင်းနိုင်ငံရှိ မြန်မာအလုပ်သမားများနှင့် မိသားစုများအတွက် ဗီဇာနှင့် စာရွက်စာတမ်း ဝန်ဆောင်မှု ဖြစ်ပါသည်။ '
                .'လျှောက်လွှာတစ်ခုစီအတွက် လိုအပ်ချက်များကို ရှင်းပြပေးပြီး စာရွက်စာတမ်းများကို သင်နှင့်အတူ ပြင်ဆင်စစ်ဆေးကာ ကိစ္စပြီးဆုံးသည်အထိ '
                .'ထိုင်းရုံးများနှင့် ဆက်လက်ဆောင်ရွက်ပေးပါသည်။ သင့်ဝန်ဆောင်မှု၏ အခြေအနေကို သင့်ကိုယ်ပိုင် ခြေရာခံလင့်ခ်ဖြင့် အွန်လိုင်းမှ ကြည့်ရှုနိုင်ပါသည်။',

            'experience' => 'Our team works every day with the Thai immigration and labour procedures that matter to Myanmar nationals: '
                .'passport services, CI, Pink Card registration, work permit applications and changes, and 90-day reports.',
            'experience_my' => 'ကျွန်ုပ်တို့အဖွဲ့သည် မြန်မာနိုင်ငံသားများနှင့် သက်ဆိုင်သော ထိုင်းလူဝင်မှုကြီးကြပ်ရေးနှင့် အလုပ်သမားဆိုင်ရာ လုပ်ငန်းစဉ်များကို '
                .'နေ့စဉ် ဆောင်ရွက်လျက်ရှိပါသည်- ပတ်စပို့ ဝန်ဆောင်မှု၊ CI၊ Pink Card မှတ်ပုံတင်ခြင်း၊ အလုပ်လုပ်ခွင့် လျှောက်ထားခြင်းနှင့် ပြောင်းလဲခြင်း၊ '
                .'၉၀ ရက် အစီရင်ခံခြင်း။',

            'mission' => 'To make every visa and work permit process clear, lawful and stress-free, so that our clients can live and work '
                .'in Thailand with confidence.',
            'mission_my' => 'ဗီဇာနှင့် အလုပ်လုပ်ခွင့် လုပ်ငန်းစဉ်တိုင်းကို ရှင်းလင်းပြီး တရားဝင်ကာ စိတ်ပူပန်မှုကင်းစေရန်နှင့် ကျွန်ုပ်တို့၏ ဝန်ဆောင်မှုယူသူများ '
                .'ထိုင်းနိုင်ငံတွင် စိတ်ချယုံကြည်စွာ နေထိုင်လုပ်ကိုင်နိုင်စေရန် ဖြစ်ပါသည်။',

            'how_we_help' => 'Tell us what you need by phone, by message or at our office. We check your documents, tell you the fee '
                .'before we start, submit the application and keep you updated until your documents are ready.',
            'how_we_help_my' => 'ဖုန်း၊ မက်ဆေ့ချ် သို့မဟုတ် ရုံးသို့ လာရောက်၍ သင်လိုအပ်သည်ကို ပြောပြပါ။ သင့်စာရွက်စာတမ်းများကို စစ်ဆေးပေးပြီး '
                .'မစတင်မီ ကုန်ကျစရိတ်ကို အသိပေးပါမည်။ လျှောက်လွှာကို တင်သွင်းပေးပြီး စာရွက်စာတမ်းများ အဆင်သင့်ဖြစ်သည်အထိ အခြေအနေကို အသိပေးသွားပါမည်။',

            // Fictional contact details, clearly not the agency's: replace them with the real ones in Settings.
            'address' => 'Sample address: 99 Example Road, Bang Rak, Bangkok 10500, Thailand',
            'address_my' => 'နမူနာ လိပ်စာ- အမှတ် 99၊ Example Road၊ Bang Rak၊ ဘန်ကောက် 10500၊ ထိုင်းနိုင်ငံ',
            'phone' => '+66 2 000 0000',
            'email' => 'info@example.com',
            'messaging_link' => 'https://example.com/message',
            'social_links' => [
                'facebook' => 'https://example.com/facebook',
                'youtube' => 'https://example.com/youtube',
            ],
        ];
    }
}
