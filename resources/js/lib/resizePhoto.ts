export const MAX_PHOTO_BYTES = 2 * 1024 * 1024;

const MAX_EDGE = 640;
const QUALITIES = [0.85, 0.7, 0.55, 0.4];

const toJpeg = (canvas: HTMLCanvasElement, quality: number): Promise<Blob> =>
    new Promise((resolve, reject) => {
        canvas.toBlob(
            (blob) => (blob ? resolve(blob) : reject(new Error('No image'))),
            'image/jpeg',
            quality,
        );
    });

/**
 * Shrinks a photo on the visitor's device before it is uploaded: the longest side becomes at most
 * 640 px, the photo is turned upright using its camera rotation, and it is saved again as a JPG
 * (which also drops the location and camera details stored inside the original file).
 * Throws when the file cannot be read as an image.
 */
export async function resizePhoto(file: File): Promise<File> {
    const bitmap = await createImageBitmap(file, {
        imageOrientation: 'from-image',
    });

    const scale = Math.min(1, MAX_EDGE / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(bitmap.width * scale));
    canvas.height = Math.max(1, Math.round(bitmap.height * scale));

    const context = canvas.getContext('2d')!;
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close();

    let blob = await toJpeg(canvas, QUALITIES[0]);

    for (const quality of QUALITIES.slice(1)) {
        if (blob.size <= MAX_PHOTO_BYTES) {
            break;
        }

        blob = await toJpeg(canvas, quality);
    }

    return new File([blob], 'photo.jpg', { type: 'image/jpeg' });
}
