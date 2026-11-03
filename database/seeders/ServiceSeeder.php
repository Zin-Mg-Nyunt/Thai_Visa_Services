<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;

class ServiceSeeder extends Seeder
{
    /**
     * Create the agency's starting service catalogue (HLD section 4.4). It only runs on an empty
     * catalogue, so running it again never duplicates a service, brings back one the admin deleted,
     * or overwrites an admin's edits. Fees that are not decided yet stay empty.
     *
     * Job Search is a service type here; its companies and workers live in the job order tables.
     */
    public function run(): void
    {
        if (Service::withTrashed()->exists()) {
            return;
        }

        $passport = Service::create([
            'title' => 'Passport',
            'title_my' => 'ပတ်စပို့',
            'public_description' => 'Passport services including Bath and MOU.',
            'public_description_my' => 'Bath နှင့် MOU အပါအဝင် ပတ်စပို့ ဝန်ဆောင်မှုများ။',
            'show_on_website' => true,
            'default_fee' => 3500,
        ]);

        foreach (['Bath', 'MOU'] as $subService) {
            Service::create([
                'parent_id' => $passport->id,
                'title' => $subService,
                'title_my' => $subService,
            ]);
        }

        Service::create([
            'title' => 'CI',
            'title_my' => 'CI',
            'public_description' => 'Certificate of identity services.',
            'public_description_my' => 'မှတ်ပုံတင်လက်မှတ် (CI) ဝန်ဆောင်မှုများ။',
            'show_on_website' => true,
        ]);

        Service::create([
            'title' => 'Pink Card',
            'title_my' => 'Pink Card',
            'public_description' => 'Pink card application support.',
            'public_description_my' => 'Pink Card လျှောက်ထားမှု ကူညီပံ့ပိုးခြင်း။',
            'show_on_website' => true,
            'default_fee' => 4000,
        ]);

        Service::create([
            'title' => 'Work Permit',
            'title_my' => 'အလုပ်လုပ်ခွင့် (Work Permit)',
            'public_description' => 'Work permit applications and document delivery.',
            'public_description_my' => 'အလုပ်လုပ်ခွင့် လျှောက်ထားမှုနှင့် စာရွက်စာတမ်း ပို့ဆောင်ခြင်း။',
            'show_on_website' => true,
            'default_fee' => 8000,
            'has_documents' => true,
        ]);

        Service::create([
            'title' => '90 Days Report',
            'title_my' => '၉၀ ရက် အစီရင်ခံခြင်း',
            'public_description' => 'Reporting every 90 days, handled for you.',
            'public_description_my' => '၉၀ ရက်တစ်ကြိမ် အစီရင်ခံခြင်းကို ကျွန်ုပ်တို့ လုပ်ပေးသည်။',
            'show_on_website' => true,
        ]);

        Service::create([
            'key' => Service::JOB_SEARCH_KEY,
            'title' => 'Job Search',
            'title_my' => 'အလုပ်ရှာဖွေရေး',
            'public_description' => 'Matching workers with hiring companies.',
            'public_description_my' => 'လုပ်သားများကို ခန့်ထားလိုသော ကုမ္ပဏီများနှင့် ချိတ်ဆက်ပေးခြင်း။',
            'show_on_website' => true,
            'has_documents' => true,
        ]);
    }
}
