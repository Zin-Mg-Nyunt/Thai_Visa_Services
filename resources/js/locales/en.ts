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
    admin: {
        comingSoon: 'This section is coming soon.',
        openMenu: 'Open menu',
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
    language: {
        label: 'Language',
        en: 'EN',
        my: 'မြန်မာ',
    },
};

export type MessageSchema = typeof en;

export default en;
