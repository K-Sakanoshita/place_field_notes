(() => {
  let exifCandidate = null;
  let selectionVersion = 0;

  function hasRange(view, offset, length) {
    return Number.isInteger(offset) && Number.isInteger(length)
      && offset >= 0 && length >= 0 && offset + length <= view.byteLength;
  }

  function textAt(view, offset, length) {
    if (!hasRange(view, offset, length)) return '';
    let value = '';
    for (let i = 0; i < length; i++) value += String.fromCharCode(view.getUint8(offset + i));
    return value;
  }

  function tiffGps(view, tiffStart) {
    if (!hasRange(view, tiffStart, 8)) return null;
    const order = textAt(view, tiffStart, 2);
    if (order !== 'II' && order !== 'MM') return null;
    const little = order === 'II';
    if (view.getUint16(tiffStart + 2, little) !== 42) return null;

    const ifd0Offset = view.getUint32(tiffStart + 4, little);
    const ifd0 = tiffStart + ifd0Offset;
    if (!hasRange(view, ifd0, 2)) return null;
    const ifd0Count = view.getUint16(ifd0, little);
    if (!hasRange(view, ifd0 + 2, ifd0Count * 12)) return null;

    let gpsPointer = null;
    for (let i = 0; i < ifd0Count; i++) {
      const entry = ifd0 + 2 + i * 12;
      if (view.getUint16(entry, little) === 0x8825) {
        gpsPointer = view.getUint32(entry + 8, little);
        break;
      }
    }
    if (gpsPointer === null) return null;

    const gpsIfd = tiffStart + gpsPointer;
    if (!hasRange(view, gpsIfd, 2)) return null;
    const gpsCount = view.getUint16(gpsIfd, little);
    if (!hasRange(view, gpsIfd + 2, gpsCount * 12)) return null;

    const entries = new Map();
    for (let i = 0; i < gpsCount; i++) {
      const entry = gpsIfd + 2 + i * 12;
      entries.set(view.getUint16(entry, little), entry);
    }

    function dataOffset(entry) {
      const type = view.getUint16(entry + 2, little);
      const count = view.getUint32(entry + 4, little);
      const sizes = { 1: 1, 2: 1, 3: 2, 4: 4, 5: 8, 7: 1, 9: 4, 10: 8 };
      const size = (sizes[type] || 0) * count;
      if (!size) return null;
      return size <= 4 ? entry + 8 : tiffStart + view.getUint32(entry + 8, little);
    }

    function reference(tag) {
      const entry = entries.get(tag);
      if (entry === undefined) return null;
      const offset = dataOffset(entry);
      if (offset === null || !hasRange(view, offset, 1)) return null;
      return String.fromCharCode(view.getUint8(offset)).toUpperCase();
    }

    function degrees(tag) {
      const entry = entries.get(tag);
      if (entry === undefined || view.getUint16(entry + 2, little) !== 5) return null;
      const count = view.getUint32(entry + 4, little);
      if (count < 3) return null;
      const offset = dataOffset(entry);
      if (offset === null || !hasRange(view, offset, 24)) return null;
      const values = [];
      for (let i = 0; i < 3; i++) {
        const numerator = view.getUint32(offset + i * 8, little);
        const denominator = view.getUint32(offset + i * 8 + 4, little);
        if (!denominator) return null;
        values.push(numerator / denominator);
      }
      return values[0] + values[1] / 60 + values[2] / 3600;
    }

    const latRef = reference(1);
    const lonRef = reference(3);
    let lat = degrees(2);
    let lon = degrees(4);
    if (lat === null || lon === null || !['N', 'S'].includes(latRef) || !['E', 'W'].includes(lonRef)) return null;
    if (latRef === 'S') lat = -lat;
    if (lonRef === 'W') lon = -lon;
    if (!Number.isFinite(lat) || !Number.isFinite(lon) || Math.abs(lat) > 90 || Math.abs(lon) > 180) return null;
    return [lon, lat];
  }

  function tiffStartForExifBlock(view, offset, length) {
    if (!hasRange(view, offset, length)) return null;
    if (length >= 6 && textAt(view, offset, 6) === 'Exif\0\0') return offset + 6;
    return offset;
  }

  function jpegGps(view) {
    if (!hasRange(view, 0, 2) || view.getUint16(0, false) !== 0xffd8) return null;
    let offset = 2;
    while (hasRange(view, offset, 4)) {
      if (view.getUint8(offset) !== 0xff) { offset++; continue; }
      const marker = view.getUint8(offset + 1);
      if (marker === 0xd9 || marker === 0xda) break;
      const segmentLength = view.getUint16(offset + 2, false);
      if (segmentLength < 2 || !hasRange(view, offset + 2, segmentLength)) break;
      if (marker === 0xe1 && segmentLength >= 8 && textAt(view, offset + 4, 6) === 'Exif\0\0') {
        return tiffGps(view, offset + 10);
      }
      offset += 2 + segmentLength;
    }
    return null;
  }

  function pngGps(view) {
    const signature = [137, 80, 78, 71, 13, 10, 26, 10];
    if (!hasRange(view, 0, signature.length) || signature.some((value, index) => view.getUint8(index) !== value)) return null;
    let offset = 8;
    while (hasRange(view, offset, 12)) {
      const size = view.getUint32(offset, false);
      const type = textAt(view, offset + 4, 4);
      const data = offset + 8;
      if (!hasRange(view, data, size + 4)) break;
      if (type === 'eXIf') {
        const start = tiffStartForExifBlock(view, data, size);
        return start === null ? null : tiffGps(view, start);
      }
      offset = data + size + 4;
    }
    return null;
  }

  function webpGps(view) {
    if (!hasRange(view, 0, 12) || textAt(view, 0, 4) !== 'RIFF' || textAt(view, 8, 4) !== 'WEBP') return null;
    let offset = 12;
    while (hasRange(view, offset, 8)) {
      const type = textAt(view, offset, 4);
      const size = view.getUint32(offset + 4, true);
      const data = offset + 8;
      if (!hasRange(view, data, size)) break;
      if (type === 'EXIF') {
        const start = tiffStartForExifBlock(view, data, size);
        return start === null ? null : tiffGps(view, start);
      }
      offset = data + size + (size % 2);
    }
    return null;
  }

  async function imageGps(file) {
    const buffer = await file.arrayBuffer();
    const view = new DataView(buffer);
    return jpegGps(view) || pngGps(view) || webpGps(view);
  }

  function installExifPositionCandidate() {
    const form = $('photo-form');
    const fileInput = form?.elements?.files;
    const actions = $('photo-position-actions');
    if (!form || !fileInput || !actions || $('use-photo-exif-position')) return;

    const button = node('button', 'secondary small', i18n.t('use_exif_position'));
    button.id = 'use-photo-exif-position';
    button.type = 'button';
    button.hidden = true;
    actions.append(button);

    const clearCandidate = () => {
      exifCandidate = null;
      button.hidden = true;
      button.removeAttribute('title');
    };

    fileInput.addEventListener('change', async () => {
      const version = ++selectionVersion;
      clearCandidate();
      const files = [...(fileInput.files || [])];
      if (files.length !== 1) return;
      const file = files[0];
      try {
        const coordinate = await imageGps(file);
        if (version !== selectionVersion || fileInput.files?.[0] !== file || !coordinate) return;
        const [lon, lat] = coordinate;
        exifCandidate = { fileName: file.name, lon, lat };
        button.hidden = false;
        button.title = `${file.name}: ${lat.toFixed(7)}, ${lon.toFixed(7)}`;
      } catch (error) {
        console.warn('Could not read EXIF GPS candidate', error);
      }
    });

    button.addEventListener('click', () => {
      if (!exifCandidate) return;
      const { fileName, lon, lat } = exifCandidate;
      const message = i18n.t('confirm_exif_position', {
        file: fileName,
        lat: lat.toFixed(7),
        lon: lon.toFixed(7),
      });
      if (!confirm(message)) return;
      form.elements.lon.value = lon.toFixed(7);
      form.elements.lat.value = lat.toFixed(7);
      setStatus(i18n.t('exif_position_applied'), 'success');
    });

    form.addEventListener('reset', () => {
      selectionVersion++;
      clearCandidate();
    });
    form.elements.source_type?.addEventListener('change', () => {
      if (form.elements.source_type.value !== 'upload') clearCandidate();
    });
  }

  const baseInitEditor = initEditor;
  initEditor = async function initEditorWithExifPosition(...args) {
    await baseInitEditor(...args);
    installExifPositionCandidate();
  };
})();
