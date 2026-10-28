import type { MessageSchema } from '@/locales/en';

const my: MessageSchema = {
    nav: {
        dashboard: 'ဒက်ရှ်ဘုတ်',
        clients: 'ဖောက်သည်များ',
        services: 'ဝန်ဆောင်မှုများ',
        jobSearch: 'အလုပ်ရှာဖွေရေး',
        settings: 'ဆက်တင်များ',
        signOut: 'ထွက်ရန်',
    },
    common: {
        save: 'သိမ်းမည်',
        cancel: 'မလုပ်တော့ပါ',
        edit: 'ပြင်ဆင်မည်',
        delete: 'ဖျက်မည်',
        search: 'ရှာဖွေရန်',
        confirm: 'အတည်ပြုမည်',
        back: 'နောက်သို့',
        adminLogin: 'အက်ဒမင် ဝင်ရန်',
    },
    serviceStatus: {
        inProgress: 'လုပ်ဆောင်နေဆဲ',
        complete: 'ပြီးစီး',
        cancel: 'ပယ်ဖျက်ပြီး',
    },
    paymentStatus: {
        unpaid: 'မပေးချေရသေး',
        deposit: 'စရန်ငွေ',
        fullyPaid: 'အပြည့်ပေးချေပြီး',
    },
    jobOrderStatus: {
        open: 'ဖွင့်ထား',
        done: 'ပြီးဆုံး',
        cancelled: 'ပယ်ဖျက်ပြီး',
    },
    landing: {
        ourServices: 'ကျွန်ုပ်တို့၏ ဝန်ဆောင်မှုများ',
        contactUs: 'ဆက်သွယ်ရန်',
    },
    language: {
        label: 'ဘာသာစကား',
        en: 'EN',
        my: 'မြန်မာ',
    },
};

export default my;
