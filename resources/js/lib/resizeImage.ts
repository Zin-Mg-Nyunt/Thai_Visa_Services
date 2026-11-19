/**
 * Shrinks a website image (logo, hero image) on the admin's device before it is uploaded, so a large file from a phone or a design
 * tool does not fail the 2 MB limit (HLD Section 3.3). It is a convenience, not a security control: the server checks the type
 * and the size again and decides.
 *
 * The profile photo has its own function, `resizePhoto()`, which this file does not change. The favicon is never processed.
 */
export const MAX_IMAGE_BYTES = 2 * 1024 * 1024;

/** The image types the browser reduces. Anything else (SVG, ICO, GIF, PDF, ...) is refused before it is read. */
export const SUPPORTED_IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

/**
 * - `keep`: save it again in the same format; PNG and WebP keep their transparency.
 * - `jpeg`: save it as a JPG on a white background (smallest for photos; transparency is lost).
 * - `webp`: save it as WebP; transparency is kept.
 */
export type ImageOutput = 'keep' | 'jpeg' | 'webp';

export type ResizeImageOptions = {
    /** The longest side becomes at most this many pixels; a smaller image is never enlarged. */
    maxEdge: number;
    output: ImageOutput;
};

/** The maximum edge and output chosen for each website image (Section 3.3 leaves the sizes to the implementation). */
export const WEBSITE_IMAGE_PRESETS = {
    // Shown small in the navigation bar; 512 px stays sharp on high-density screens. PNG and WebP keep transparency.
    logo: { maxEdge: 512, output: 'keep' },
    // Shown wide in the hero; 1600 px is enough for a full-width banner. A photo, so a JPG.
    hero: { maxEdge: 1600, output: 'jpeg' },
} as const satisfies Record<string, ResizeImageOptions>;

/** The file is not a PNG, JPG or WebP image. */
export class UnsupportedImageError extends Error {}

/** The image could not be brought to 2 MB or less. Nothing should be uploaded. */
export class ImageTooLargeError extends Error {}

const QUALITIES = [0.85, 0.7, 0.55, 0.4];
const MAX_SHRINKS = 5;
const SHRINK_FACTOR = 0.8;

/** The size after fitting inside `maxEdge` on the longest side, never enlarging and never below 1 px. */
export function fitWithin(
    width: number,
    height: number,
    maxEdge: number,
): { width: number; height: number } {
    const scale = Math.min(1, maxEdge / Math.max(width, height));

    return {
        width: Math.max(1, Math.round(width * scale)),
        height: Math.max(1, Math.round(height * scale)),
    };
}

/** The type to save as: for `keep`, the same type as the file. */
export function outputType(sourceType: string, output: ImageOutput): string {
    if (output === 'jpeg') {
        return 'image/jpeg';
    }

    if (output === 'webp') {
        return 'image/webp';
    }

    return sourceType;
}

const extensionOf = (type: string): string =>
    type === 'image/jpeg' ? 'jpg' : type.replace('image/', '');

const toBlob = (
    canvas: HTMLCanvasElement,
    type: string,
    quality?: number,
): Promise<Blob> =>
    new Promise((resolve, reject) => {
        canvas.toBlob(
            (blob) => (blob ? resolve(blob) : reject(new Error('No image'))),
            type,
            quality,
        );
    });

function draw(
    bitmap: ImageBitmap,
    size: { width: number; height: number },
    opaque: boolean,
): HTMLCanvasElement {
    const canvas = document.createElement('canvas');
    canvas.width = size.width;
    canvas.height = size.height;

    const context = canvas.getContext('2d')!;

    // A JPG has no transparency: without a background the transparent parts would turn black.
    if (opaque) {
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, size.width, size.height);
    }

    context.drawImage(bitmap, 0, 0, size.width, size.height);

    return canvas;
}

/**
 * Reduces an image: the longest side becomes at most `maxEdge`, it is turned upright using its camera rotation, and it is saved
 * again in the chosen format (which also drops the location and camera details stored inside the original file). JPG and WebP
 * lower their quality step by step until the file is 2 MB or less; a PNG cannot lower its quality, so it is made 20% smaller,
 * up to five times, instead.
 *
 * @throws UnsupportedImageError when the file is not a PNG, JPG or WebP image (checked first, nothing is read)
 * @throws ImageTooLargeError when it still cannot be brought to 2 MB or less
 * @throws Error when the file cannot be read as an image
 */
export async function resizeImage(
    file: File,
    { maxEdge, output }: ResizeImageOptions,
): Promise<File> {
    if (!SUPPORTED_IMAGE_TYPES.includes(file.type)) {
        throw new UnsupportedImageError(file.type);
    }

    const type = outputType(file.type, output);
    const bitmap = await createImageBitmap(file, {
        imageOrientation: 'from-image',
    });

    try {
        let size = fitWithin(bitmap.width, bitmap.height, maxEdge);

        for (let shrink = 0; shrink <= MAX_SHRINKS; shrink++) {
            const canvas = draw(bitmap, size, type === 'image/jpeg');

            // PNG ignores the quality, so only the first try is different from the rest; JPG and WebP walk down the qualities.
            for (const quality of type === 'image/png'
                ? [undefined]
                : QUALITIES) {
                const blob = await toBlob(canvas, type, quality);

                if (blob.size <= MAX_IMAGE_BYTES) {
                    // The browser may not support WebP and give a PNG instead; the file then says what it really is.
                    const actual = blob.type || type;

                    return new File([blob], `image.${extensionOf(actual)}`, {
                        type: actual,
                    });
                }
            }

            // Only a PNG is shrunk further: a lossy image has already tried every quality and is refused.
            if (type !== 'image/png') {
                break;
            }

            size = {
                width: Math.max(1, Math.round(size.width * SHRINK_FACTOR)),
                height: Math.max(1, Math.round(size.height * SHRINK_FACTOR)),
            };
        }
    } finally {
        bitmap.close();
    }

    throw new ImageTooLargeError(file.name);
}
