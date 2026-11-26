/**
 * The browser tab title: the page's own title followed by the brand name (the agency name, or the app name when there is none),
 * unless the title already contains it. This keeps the landing page, whose title is the brand name itself, from showing
 * "Min Min - Min Min".
 */
export function documentTitle(title: string, brandName: string): string {
    if (!title) {
        return brandName;
    }

    return title.toLowerCase().includes(brandName.toLowerCase())
        ? title
        : `${title} - ${brandName}`;
}
