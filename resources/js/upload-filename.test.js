import { test, expect } from 'bun:test';
import { safeUploadName } from './upload-filename';

// The actual filename that triggered Hostinger's WAF 403 in production.
test('strips the apostrophe that trips the WAF', () => {
    expect(safeUploadName("Kboges' Pull Up Mastery.pdf")).toBe('Kboges-Pull-Up-Mastery.pdf');
});

test('folds spaces to a single dash', () => {
    expect(safeUploadName('Pull Up Mastery.pdf')).toBe('Pull-Up-Mastery.pdf');
});

test('removes apostrophes without leaving double dashes', () => {
    expect(safeUploadName("photo'.jpg")).toBe('photo.jpg');
});

test('passes through a name with no extension', () => {
    expect(safeUploadName('README')).toBe('README');
});

test('falls back to "upload" when the stem sanitizes to empty', () => {
    expect(safeUploadName("'''.pdf")).toBe('upload.pdf');
});

test('leaves an already-clean name unchanged', () => {
    expect(safeUploadName('clean-file_v2.pdf')).toBe('clean-file_v2.pdf');
});

test('only splits on the last dot, so a double extension keeps its inner dot', () => {
    expect(safeUploadName("my'file.tar.gz")).toBe('my-file.tar.gz');
});

test('caps a pathologically long stem at 100 characters', () => {
    const long = 'a'.repeat(300) + '.pdf';
    const result = safeUploadName(long);
    expect(result).toBe('a'.repeat(100) + '.pdf');
});
