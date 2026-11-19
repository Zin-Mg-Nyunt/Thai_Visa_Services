import { existsSync, renameSync } from 'node:fs';
import { ROOT, STALE_HOT } from './environment';

/** After the browser tests: put back the public/hot file that was moved aside, unless a new one has appeared meanwhile. */
export default async function globalTeardown(): Promise<void> {
    if (existsSync(STALE_HOT) && !existsSync(`${ROOT}/public/hot`)) {
        renameSync(STALE_HOT, `${ROOT}/public/hot`);
    }
}
