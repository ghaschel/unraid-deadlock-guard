export const minimumApiVersion = '4.36.0';

export function apiVersionError(version) {
  const shown = typeof version === 'string' && version !== '' ? version : '(missing or invalid)';
  const error = `Unsupported Unraid API version ${shown}; requires ${minimumApiVersion} or newer.`;
  if (typeof version !== 'string' || version.length > 255) return error;
  const parts =
    /^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/.exec(
      version,
    );
  if (!parts || parts[0] !== version) return error;
  const prerelease = parts[4] || '';
  if (prerelease.split('.').some((id) => /^[0-9]+$/.test(id) && id.length > 1 && id[0] === '0'))
    return error;
  const minimum = minimumApiVersion.split('.').map(BigInt);
  for (let index = 0; index < minimum.length; index++) {
    const actual = BigInt(parts[index + 1]);
    if (actual !== minimum[index]) return actual > minimum[index] ? null : error;
  }
  return prerelease ? error : null;
}
