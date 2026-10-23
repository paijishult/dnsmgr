// @php-wasm/node selects a major.minor series, not an exact patch release.
// Verify the binary after loading so an older cached build cannot pass as 8.3.36.
export async function loadCheckedPHPRuntime({ PHP, loadNodeRuntime, requested = process.env.PHP_VERSION || '8.3.36' }) {
  if (!/^\d+\.\d+(?:\.\d+)?$/.test(requested)) {
    throw new Error(`Invalid PHP_VERSION: ${requested}`);
  }
  const series = requested.split('.').slice(0, 2).join('.');
  const php = new PHP(await loadNodeRuntime(series, { emscriptenOptions: { processId: 1 } }));
  try {
    const result = await php.run({ code: '<?php echo PHP_VERSION;' });
    const actual = result.text.trim();
    const matches = requested.split('.').length === 3
      ? actual === requested
      : actual.startsWith(series + '.');
    const patch = Number(actual.split('.')[2]);
    if (result.exitCode || !matches || (series === '8.3' && (!Number.isInteger(patch) || patch < 36))) {
      throw new Error(`Requested PHP ${requested} (8.3 requires patch 36 or newer), but the installed WASM provides ${actual || 'no version'}. Update the @php-wasm packages or use native PHP/Docker.`);
    }
    return php;
  } catch (error) {
    php.exit();
    throw error;
  }
}
