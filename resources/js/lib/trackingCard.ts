export type TrackingCardOptions = {
    /** The name beside the logo; null draws the logo alone. */
    siteName: string | null;
    clientName: string;
    hint: string;
    qrDataUrl: string;
    /** The client's photo; the first letter of the name is drawn when there is none or it cannot load. */
    photoUrl?: string | null;
    /** The logo shown in the modal; its computed fills are copied so it renders outside the page. */
    logo: SVGSVGElement | null;
};

const CARD_WIDTH = 360;
const PADDING = 20;
const SCALE = 3;
const FONT_FAMILY =
    '"Inter Variable", "Noto Sans Myanmar Variable", sans-serif';

const themeColor = (name: string, fallback: string): string =>
    getComputedStyle(document.documentElement).getPropertyValue(name).trim() ||
    fallback;

const loadImage = (src: string): Promise<HTMLImageElement> =>
    new Promise((resolve, reject) => {
        const image = new Image();
        image.onload = () => resolve(image);
        image.onerror = reject;
        image.src = src;
    });

const logoToImage = async (logo: SVGSVGElement): Promise<HTMLImageElement> => {
    const clone = logo.cloneNode(true) as SVGSVGElement;
    const sourcePaths = logo.querySelectorAll('path');

    clone.querySelectorAll('path').forEach((path, index) => {
        path.setAttribute('fill', getComputedStyle(sourcePaths[index]).fill);
    });
    clone.setAttribute('width', '256');
    clone.setAttribute('height', '256');

    const svg = new XMLSerializer().serializeToString(clone);

    return loadImage(
        `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`,
    );
};

/** Wrap by words, and by characters when a word alone is too wide (Burmese has no spaces). */
const wrapText = (
    context: CanvasRenderingContext2D,
    text: string,
    maxWidth: number,
): string[] => {
    const lines: string[] = [];
    let line = '';

    for (const word of text.split(/(\s+)/)) {
        if (context.measureText(line + word).width <= maxWidth) {
            line += word;
            continue;
        }

        if (line.trim() !== '') {
            lines.push(line.trim());
            line = '';
        }

        for (const character of Array.from(word.trimStart())) {
            if (context.measureText(line + character).width > maxWidth) {
                lines.push(line);
                line = '';
            }

            line += character;
        }
    }

    if (line.trim() !== '') {
        lines.push(line.trim());
    }

    return lines;
};

const roundedRect = (
    context: CanvasRenderingContext2D,
    x: number,
    y: number,
    width: number,
    height: number,
    radius: number,
): void => {
    context.beginPath();
    context.roundRect(x, y, width, height, radius);
};

/**
 * Draws the same ID card the admin sees in the modal and returns it as a PNG.
 */
export async function renderTrackingCard(
    options: TrackingCardOptions,
): Promise<Blob> {
    const brandDark = themeColor('--brand-dark', '#023842');
    const brand = themeColor('--brand', '#00d47f');
    const mint = themeColor('--mint', '#e3f6ee');
    const muted = themeColor('--muted-foreground', '#4a6b70');

    await Promise.all([
        document.fonts.load(`700 22px ${FONT_FAMILY}`, options.clientName),
        document.fonts.load(`400 12px ${FONT_FAMILY}`, options.hint),
    ]);

    const [qrImage, logoImage, photoImage] = await Promise.all([
        loadImage(options.qrDataUrl),
        options.logo ? logoToImage(options.logo) : Promise.resolve(null),
        options.photoUrl
            ? loadImage(options.photoUrl).catch(() => null)
            : Promise.resolve(null),
    ]);

    const contentWidth = CARD_WIDTH - PADDING * 2;
    const measure = document.createElement('canvas').getContext('2d')!;

    measure.font = `700 22px ${FONT_FAMILY}`;
    const nameLines = wrapText(measure, options.clientName, contentWidth);
    measure.font = `400 12px ${FONT_FAMILY}`;
    const hintLines = wrapText(measure, options.hint, contentWidth);

    const headerHeight = 96;
    const avatarRadius = 40;
    const avatarCenterY = headerHeight;
    const nameTop = avatarCenterY + avatarRadius + 14;
    const nameLineHeight = 30;
    const panelTop = nameTop + nameLines.length * nameLineHeight + 16;
    const panelSize = contentWidth;
    const qrSize = panelSize - 40;
    const hintTop = panelTop + panelSize + 16;
    const hintLineHeight = 18;
    const stripHeight = 8;
    const height =
        hintTop + hintLines.length * hintLineHeight + 24 + stripHeight;

    const canvas = document.createElement('canvas');
    canvas.width = CARD_WIDTH * SCALE;
    canvas.height = height * SCALE;

    const context = canvas.getContext('2d')!;
    context.scale(SCALE, SCALE);
    context.textAlign = 'center';
    context.textBaseline = 'middle';

    context.save();
    roundedRect(context, 0, 0, CARD_WIDTH, height, 24);
    context.clip();

    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, CARD_WIDTH, height);

    context.fillStyle = brandDark;
    context.fillRect(0, 0, CARD_WIDTH, headerHeight);

    if (logoImage) {
        context.drawImage(logoImage, PADDING, PADDING, 36, 36);
    }

    context.textAlign = 'left';
    context.fillStyle = '#ffffff';
    context.font = `700 16px ${FONT_FAMILY}`;
    if (options.siteName) {
        context.fillText(
            options.siteName,
            PADDING + 36 + 12,
            PADDING + 18,
            CARD_WIDTH - PADDING * 2 - 48,
        );
    }
    context.textAlign = 'center';

    context.fillStyle = '#ffffff';
    context.beginPath();
    context.arc(
        CARD_WIDTH / 2,
        avatarCenterY,
        avatarRadius + 4,
        0,
        Math.PI * 2,
    );
    context.fill();
    context.fillStyle = mint;
    context.beginPath();
    context.arc(CARD_WIDTH / 2, avatarCenterY, avatarRadius, 0, Math.PI * 2);
    context.fill();

    if (photoImage) {
        // Fill the circle with the photo, cropping the longer side like object-fit: cover.
        const side = Math.min(photoImage.width, photoImage.height);

        context.save();
        context.beginPath();
        context.arc(
            CARD_WIDTH / 2,
            avatarCenterY,
            avatarRadius,
            0,
            Math.PI * 2,
        );
        context.clip();
        context.drawImage(
            photoImage,
            (photoImage.width - side) / 2,
            (photoImage.height - side) / 2,
            side,
            side,
            CARD_WIDTH / 2 - avatarRadius,
            avatarCenterY - avatarRadius,
            avatarRadius * 2,
            avatarRadius * 2,
        );
        context.restore();
    } else {
        context.fillStyle = brandDark;
        context.font = `700 36px ${FONT_FAMILY}`;
        context.fillText(
            Array.from(options.clientName)[0]?.toUpperCase() ?? '',
            CARD_WIDTH / 2,
            avatarCenterY + 2,
        );
    }

    context.font = `700 22px ${FONT_FAMILY}`;
    context.fillStyle = brandDark;
    nameLines.forEach((line, index) => {
        context.fillText(
            line,
            CARD_WIDTH / 2,
            nameTop + index * nameLineHeight + nameLineHeight / 2,
        );
    });

    context.fillStyle = mint;
    roundedRect(context, PADDING, panelTop, panelSize, panelSize, 16);
    context.fill();
    context.fillStyle = '#ffffff';
    roundedRect(
        context,
        PADDING + 12,
        panelTop + 12,
        panelSize - 24,
        panelSize - 24,
        12,
    );
    context.fill();
    context.drawImage(qrImage, PADDING + 20, panelTop + 20, qrSize, qrSize);

    context.font = `400 12px ${FONT_FAMILY}`;
    context.fillStyle = muted;
    hintLines.forEach((line, index) => {
        context.fillText(
            line,
            CARD_WIDTH / 2,
            hintTop + index * hintLineHeight + hintLineHeight / 2,
        );
    });

    context.fillStyle = brand;
    context.fillRect(0, height - stripHeight, CARD_WIDTH, stripHeight);
    context.restore();

    return new Promise((resolve, reject) => {
        canvas.toBlob(
            (blob) => (blob ? resolve(blob) : reject(new Error('No image'))),
            'image/png',
        );
    });
}
