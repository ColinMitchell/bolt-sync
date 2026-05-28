export function noCachePath(path) {
    const separator = path.includes('?') ? '&' : '?';
    return `${path}${separator}_=${Date.now()}`;
}