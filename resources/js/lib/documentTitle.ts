/**
 * The browser tab title: the page's own title followed by the app name, unless the title already contains the app name. This keeps
 * the landing page, whose title is the agency name (often the app name itself), from showing "PaingThu - PaingThu".
 */
export function documentTitle(title: string, appName: string): string {
    if (!title) {
        return appName;
    }

    return title.toLowerCase().includes(appName.toLowerCase())
        ? title
        : `${title} - ${appName}`;
}
