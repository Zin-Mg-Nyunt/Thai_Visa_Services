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
    },
    roles: {
        superadmin: 'Superadmin',
    },
    admin: {
        comingSoon: 'This section is coming soon.',
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
