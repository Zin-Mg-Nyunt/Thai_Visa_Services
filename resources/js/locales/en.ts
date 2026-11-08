const en = {
    nav: {
        dashboard: 'Dashboard',
        clients: 'Clients',
        services: 'Services',
        jobSearch: 'Job Search',
        settings: 'Settings',
        signOut: 'Sign out',
    },
    common: {
        save: 'Save',
        cancel: 'Cancel',
        edit: 'Edit',
        delete: 'Delete',
        search: 'Search',
        confirm: 'Confirm',
        back: 'Back',
        adminLogin: 'Admin Login',
    },
    serviceStatus: {
        inProgress: 'In Progress',
        complete: 'Complete',
        cancel: 'Cancel',
    },
    serviceAvailability: {
        active: 'Active',
        inactive: 'Inactive',
    },
    paymentStatus: {
        unpaid: 'Unpaid',
        deposit: 'Deposit',
        fullyPaid: 'Fully Paid',
    },
    jobOrderStatus: {
        open: 'Open',
        done: 'Done',
        cancelled: 'Cancelled',
    },
    landing: {
        ourServices: 'Our Services',
        contactUs: 'Contact Us',
        rights: '© {name}. All rights reserved.',
        hero: {
            eyebrow: 'Visa & employment services',
            titleStart: 'Visas and work permits,',
            titleHighlight: 'handled with care.',
            description:
                'We help workers and employers with visas, work permits and job placement in Thailand, with clear progress tracking at every step.',
            photoTag: 'Sample photo',
        },
        about: {
            eyebrow: 'About',
            title: 'Who we are',
            experienceTitle: 'Experience',
            experienceText:
                '10+ years helping clients with Thai visa and employment paperwork.',
            missionTitle: 'Mission',
            missionText:
                'Make visa and work-permit services simple, transparent and reliable.',
            helpTitle: 'How we help',
            helpText:
                'Dedicated support, honest pricing and live status tracking for every case.',
        },
        services: {
            eyebrow: 'Services',
            title: 'What we do for you',
            workPermitTitle: 'Work Permit',
            workPermitText: 'Work permit applications and document delivery.',
            passportTitle: 'Passport',
            passportText: 'Passport services including Bath and MOU.',
            pinkCardTitle: 'Pink Card',
            pinkCardText: 'Pink card application support.',
            reportTitle: '90 Days Report',
            reportText: 'Reporting every 90 days, handled for you.',
            ciTitle: 'CI',
            ciText: 'Certificate of identity services.',
            jobSearchTitle: 'Job Search',
            jobSearchText: 'Matching workers with hiring companies.',
        },
        stats: {
            clients: 'Clients served',
            services: 'Services provided',
        },
        contact: {
            eyebrow: 'Contact',
            title: 'Talk to us',
            address: 'Bangkok, Thailand',
            callNow: 'Call now',
            message: 'Message us',
        },
    },
    roles: {
        superadmin: 'Superadmin',
    },
    admin: {
        comingSoon: 'This section is coming soon.',
    },
    dashboard: {
        title: 'Business dashboard',
        showing: 'Showing {from} to {to}',
        range: {
            label: 'Date range',
            thisMonth: 'This month',
            lastMonth: 'Last month',
            thisYear: 'This year',
            custom: 'Custom range',
            from: 'From',
            to: 'To',
            apply: 'Apply',
        },
        cards: {
            received: 'Total received',
            refunded: 'Refunded: {amount}',
            outstanding: 'Outstanding',
            outstandingHint: 'Balance still owed today',
            profit: 'Profit',
            profitSoon: 'Not available yet',
            comingSoon: 'Coming soon',
            activeClients: 'Active clients',
            activeClientsHint: 'Clients with a service in progress today',
        },
        byMonth: {
            title: 'Received by month',
            hint: 'The last 12 months up to the end of the chosen range.',
        },
        byService: {
            title: 'By service',
            empty: 'No money received in this range.',
        },
    },
    clients: {
        title: 'Clients',
        new: 'New client',
        searchPlaceholder: 'Search by name or phone',
        empty: 'No clients found.',
        columns: {
            name: 'Name',
            phone: 'Phone',
            birthday: 'Birthday',
            gender: 'Gender',
        },
        gender: {
            male: 'Male',
            female: 'Female',
        },
        filters: {
            service: 'Service',
            allServices: 'All services',
            subService: 'Sub-service',
            allSubServices: 'All sub-services',
            status: 'Status',
            anyStatus: 'Any status',
            clear: 'Clear filters',
        },
        pagination: {
            showing: 'Showing {from}–{to} of {total}',
            page: 'Page {current} of {last}',
            previous: 'Previous',
            next: 'Next',
        },
        form: {
            name: 'Name',
            phone: 'Phone',
            phoneHint:
                'Use international format, for example +66 81 234 5678. A number starting with 0 is saved as a Thai number (+66).',
            birthday: 'Birthday',
            gender: 'Gender',
            genderPlaceholder: 'Select gender',
            remarks: 'Remarks',
            remarksPlaceholder: 'Optional note',
        },
        create: {
            head: 'New client',
            title: 'New client',
        },
        edit: {
            head: 'Edit client',
            title: 'Edit client',
        },
        delete: {
            title: 'Delete this client?',
            description:
                'The client will be removed from the lists, and their profile photo and PDF documents will be permanently erased. This cannot be undone. Payment totals and the client count stay in the reports.',
            confirmLabel: 'Type {phone} to confirm',
        },
        show: {
            profile: 'Profile',
            noRemarks: 'No remarks',
        },
        pinLog: {
            show: 'Show log',
            hide: 'Hide log',
            title: 'Wrong PIN log',
            description:
                "Wrong PINs typed on this client's link, newest first. The PIN itself is never saved. Five wrong tries lock the link for 15 minutes.",
            locked: 'The link is locked. About {minutes} min left.',
            unlock: 'Unlock link',
            empty: 'No wrong PINs so far.',
            time: 'Time',
            ip: 'IP address',
            browser: 'Browser',
            result: 'Result',
            wrong: 'Wrong PIN',
            lockedResult: 'Locked the link',
            latest: 'Showing the latest {count} wrong PINs.',
        },
        photo: {
            upload: 'Upload photo',
            change: 'Change photo',
            remove: 'Remove photo',
            hint: 'JPG only, up to 2 MB. It is made smaller on your device before upload.',
            invalidType: 'Choose a JPG photo.',
            unreadable: 'This photo could not be read. Choose another JPG.',
        },
        tracking: {
            generateQr: 'Generate QR code',
            scanHint:
                'Scan the QR code, then enter the last 4 digits of your phone number.',
            title: 'Share access',
            description:
                'Send this link or QR code to the client. They also need the last 4 digits of their phone number to sign in.',
            linkLabel: 'Tracking link',
            copy: 'Copy link',
            copied: 'Link copied',
            qrAlt: 'QR code for the tracking link',
            downloadCard: 'Download card',
        },
    },
    clientServices: {
        title: 'Subscribed services',
        add: 'Add service',
        empty: 'No services added yet.',
        mainOnly: 'Main service only',
        fee: 'Fee',
        received: 'Received',
        remaining: 'Remaining',
        payment: 'Payment',
        notAvailable: '–',
        kept: 'Kept',
        refund: 'Refund',
        cancelRefund: 'Cancel refund',
        remarks: {
            history: 'Remarks ({count})',
            automatic: 'Automatic',
            unknownAuthor: 'Deleted user',
            refundAdded:
                'Fee changed from {from} to {to} after {received} was received. {refund} is to be refunded to the client.',
            refundCleared:
                'Fee changed from {from} to {to}. No refund is due any more.',
            cancelled:
                'Service cancelled after {received} was received. Cancel refund {cancelRefund}. Kept {kept}.',
            cancelledWithRefund:
                'Service cancelled after {received} was received. Refund {refund} from fee changes, cancel refund {cancelRefund}. Kept {kept}.',
        },
        cancel: {
            button: 'Cancel service',
            title: 'Cancel this service?',
            kept: 'Kept {amount}',
            description:
                'The service stays in the list as history with Cancel status. Nothing more is owed on it and no new payments can be added. This cannot be undone.',
            refund: 'Cancel refund (฿)',
            refundHint:
                'It is taken off what you keep, and cannot be more than {amount}. Optional. If you do not fill it, the cancel refund will be set to 0.',
            keep: 'Keep service',
            confirm: 'Cancel service',
        },
        summary: {
            services: 'Services',
            received: 'Total received (after refunds)',
            remaining: 'Remaining',
        },
        form: {
            cancelledNotice:
                'This service is cancelled. You can only add a remark.',
            service: 'Service',
            servicePlaceholder: 'Select a service',
            serviceLocked:
                'The service cannot be changed. To use another service, add a new one.',
            subService: 'Sub-service',
            status: 'Status',
            fee: 'Service fee (฿)',
            feeHint:
                "Filled from the sub-service fee, or the service's default fee. You can change it.",
            feeChanged:
                'The fee changed from {from} to {to}, the default fee of {name}. Change it if you agreed a different price with the client.',
            startDate: 'Start date',
            endDate: 'End date',
            receivedNow: 'Received now (฿)',
            receivedNowHint:
                'Optional. If the client paid something today, it is saved as the first cash payment.',
            remark: 'Remark',
            addRemark: 'Add a remark',
            remarkHint:
                'Saved to the list of remarks with your name and the time. Earlier remarks stay as they are.',
            refundWarning:
                '{received} is already received, more than this fee. When you save, {refund} will be recorded as a refund to the client.',
        },
        create: {
            head: 'Add service',
            title: 'Add service',
        },
        edit: {
            head: 'Edit service',
            title: 'Edit service',
        },
    },
    documents: {
        title: 'Documents',
        upload: 'Upload',
        empty: 'No documents yet.',
        release: 'Release to client',
        limits: 'PDF up to 5 MB, JPG or PNG up to 2 MB.',
        fields: {
            title: 'Title',
            file: 'File',
        },
        state: {
            released: 'Released',
            unlockedByPayment: 'Unlocked (fully paid)',
            locked: 'Locked',
        },
        dialog: {
            upload: 'Upload a document',
            rename: 'Edit document',
        },
        delete: {
            title: 'Delete this document?',
            description:
                '“{title}” and its file will be deleted for good. The access history is kept.',
        },
    },
    payments: {
        record: 'Record payment',
        history: 'Payments ({count})',
        amount: 'Amount (฿)',
        date: 'Date',
        method: 'Method',
        note: 'Note',
        notePlaceholder: 'Optional note',
        methods: {
            cash: 'Cash',
        },
        dialog: {
            title: 'Record payment',
            remaining: 'Remaining {amount}',
        },
    },
    services: {
        title: 'Services',
        new: 'New service',
        addSubService: 'Add sub-service',
        empty: 'No services yet.',
        defaultFee: 'Default fee',
        noFee: 'No default fee',
        subServices: 'Sub-services',
        subServicesCount: '{count} sub-services',
        noSubServices: 'No sub-services yet.',
        subServiceOf: 'Sub-service of {name}',
        untitled: 'Untitled service',
        jobSearchNote:
            'Job Search is managed from its own module. It cannot have sub-services and cannot be deleted.',
        stats: {
            totalClients: 'Total clients',
            inProgress: 'In progress',
            complete: 'Complete',
            totalReceived: 'Total received (after refunds)',
            notAvailable: '–',
        },
        breakdown: {
            title: 'Sub-service breakdown',
            hint: 'Each row counts the client services of this service. Together the rows add up to the totals above.',
            name: 'Sub-service',
            viewClients: 'View clients',
        },
        details: {
            title: 'Service details',
            titleEn: 'Title (English)',
            titleMy: 'Title (Burmese)',
            parent: 'Parent service',
            none: 'None',
            status: 'Status',
            defaultFee: 'Default fee',
            hasDocuments: 'Has documents',
            showOnWebsite: 'Show on website',
            description: 'Public description',
            descriptionEn: 'English',
            descriptionMy: 'Burmese',
            yes: 'Yes',
            no: 'No',
            notSet: 'Not set',
        },
        form: {
            titleEn: 'Title (English)',
            titleMy: 'Title (Burmese)',
            parent: 'Parent service',
            parentNone: 'None (main service)',
            parentHint:
                'Choose a parent to make this a sub-service. Sub-services are not shown on the website.',
            parentLocked:
                'This service stays a main service because it has sub-services or is the Job Search service.',
            status: 'Status',
            defaultFee: 'Default fee (฿)',
            feeHint:
                'Optional. A sub-service without a fee uses its main service fee.',
            options: 'Options',
            hasDocuments: 'Has documents',
            showOnWebsite: 'Show on website',
            subServiceNotShown: 'Sub-services are never shown on the website.',
            descriptionTitle: 'Public description',
            descriptionEn: 'English',
            descriptionMy: 'Burmese',
            languageHint:
                'Enter a title and a description in at least one language.',
            subServiceLanguageHint:
                'Enter a title in at least one language. The description is optional for a sub-service.',
        },
        create: {
            head: 'New service',
            title: 'New service',
            subTitle: 'New sub-service',
        },
        edit: {
            head: 'Edit service',
            title: 'Edit service',
        },
        delete: {
            title: 'Delete this service?',
            description:
                'The service disappears from the lists and the website and can no longer be chosen for new clients. Its sub-services, clients, payments and reports stay unchanged.',
            subDescription:
                'The sub-service can no longer be chosen for new clients. Existing clients, payments and reports stay unchanged.',
        },
    },
    settings: {
        title: 'Settings',
        description: 'Manage your profile and account settings',
        nav: {
            profile: 'Profile',
            security: 'Security',
        },
        profile: {
            head: 'Profile settings',
            title: 'Profile',
            description: 'Update your name and email address',
            unverified: 'Your email address is unverified.',
            resend: 'Click here to re-send the verification email.',
            linkSent:
                'A new verification link has been sent to your email address.',
        },
        security: {
            head: 'Security settings',
            title: 'Update password',
            description:
                'Ensure your account is using a long, random password to stay secure',
            currentPassword: 'Current password',
            newPassword: 'New password',
        },
    },
    auth: {
        name: 'Name',
        namePlaceholder: 'Full name',
        email: 'Email address',
        password: 'Password',
        confirmPassword: 'Confirm password',
        rememberMe: 'Remember me',
        forgotPassword: 'Forgot your password?',
        showPassword: 'Show password',
        hidePassword: 'Hide password',
        login: {
            head: 'Log in',
            title: 'Log in to your account',
            description: 'Enter your email and password below to log in',
            submit: 'Log in',
            google: 'Continue with Google',
            or: 'or',
        },
        forgot: {
            head: 'Forgot password',
            title: 'Forgot password',
            description: 'Enter your email to receive a password reset link',
            submit: 'Email password reset link',
            returnTo: 'Or, return to',
            logIn: 'log in',
        },
        reset: {
            head: 'Reset password',
            title: 'Reset password',
            description: 'Please enter your new password below',
            submit: 'Reset password',
        },
        confirm: {
            head: 'Confirm password',
            title: 'Confirm password',
            description:
                'This is a secure area of the application. Please confirm your password before continuing.',
            submit: 'Confirm password',
        },
        verify: {
            head: 'Email verification',
            title: 'Email verification',
            description:
                'Please verify your email address by clicking on the link we just emailed to you.',
            sent: 'A new verification link has been sent to the email address you provided during registration.',
            resend: 'Resend verification email',
            logOut: 'Log out',
        },
    },
    track: {
        head: 'Track your case',
        title: 'Track your case',
        description:
            'Enter your 4-digit PIN (last 4 digits of your phone number)',
        pinLabel: 'PIN',
        digitLabel: 'PIN digit {number}',
        locked: 'Too many wrong PINs. This link is locked. Please try again in {minutes} minutes.',
        submit: 'View my progress',
    },
    clientArea: {
        head: 'My profile',
        welcome: 'Welcome, {name}',
        intro: 'Your case progress will appear here.',
        detailsTitle: 'Your details',
        contactAgency: 'If any detail is wrong, please contact the agency.',
        signOut: 'Sign out',
    },
    language: {
        label: 'Language',
        switchTo: 'Switch language to {language}',
        en: 'EN',
        my: 'MY',
        name: {
            en: 'English',
            my: 'မြန်မာ',
        },
    },
};

export type MessageSchema = typeof en;

export default en;
