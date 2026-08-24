import { test, expect } from 'bun:test';
import { parseExternalTotInvite, parseHumanDate } from './external-tot-paste';

const NEOCLOUD_INVITE = `Hi Partner,

We are pleased to invite you to Cybersecurity in the Age of NeoCloud, an exclusive workshop designed for partners and cybersecurity professionals looking to understand how AI, Cloud, and Cybersecurity come together to shape modern digital services.

*Event Details:*
Date: Friday, 28th August 2026
Time: 10:00 AM – 12:00 PM
Venue: Techdata Systems, Level 3 Conference Room
|📍 https://maps.app.goo.gl/pb47NuLjfLLRsP4t6

During this workshop, you will gain insights into:
* How AI, Cloud, and Cybersecurity are coming together in today's digital landscape
* How to identify emerging opportunities in the evolving cybersecurity market

⚠️ Seats are limited, register early to secure your spot.

🔗 Registration: https://forms.gle/M7NSkbmbnbr64pZC7

We look forward to welcoming you.`;

test('reads the title out of the "invite you to X," clause, not the greeting', () => {
    expect(parseExternalTotInvite(NEOCLOUD_INVITE).title).toBe('Cybersecurity in the Age of NeoCloud');
});

test('reads the labelled date and converts it to YYYY-MM-DD', () => {
    expect(parseExternalTotInvite(NEOCLOUD_INVITE).event_date).toBe('2026-08-28');
});

test('reads the labelled time verbatim, no reformatting', () => {
    expect(parseExternalTotInvite(NEOCLOUD_INVITE).time_label).toBe('10:00 AM – 12:00 PM');
});

test('reads the labelled venue', () => {
    expect(parseExternalTotInvite(NEOCLOUD_INVITE).venue).toBe('Techdata Systems, Level 3 Conference Room');
});

test('classifies the maps.app.goo.gl link as the venue map, not registration', () => {
    expect(parseExternalTotInvite(NEOCLOUD_INVITE).venue_map_url).toBe('https://maps.app.goo.gl/pb47NuLjfLLRsP4t6');
});

test('classifies the forms.gle link as registration, not the venue map', () => {
    expect(parseExternalTotInvite(NEOCLOUD_INVITE).registration_url).toBe('https://forms.gle/M7NSkbmbnbr64pZC7');
});

test('leaves every field blank for text with no recognisable structure, never a guess', () => {
    const parsed = parseExternalTotInvite('just some random forwarded text with no labels at all');
    expect(parsed.event_date).toBe('');
    expect(parsed.time_label).toBe('');
    expect(parsed.venue).toBe('');
    expect(parsed.venue_map_url).toBe('');
    expect(parsed.registration_url).toBe('');
});

test('falls back to the first line as the title when there is no greeting', () => {
    expect(parseExternalTotInvite('Q3 Security Summit\nDate: 1 January 2027').title).toBe('Q3 Security Summit');
});

test('leaves an unrecognised link unclassified rather than guessing which field it belongs in', () => {
    const parsed = parseExternalTotInvite('Details: https://example.com/event/123');
    expect(parsed.venue_map_url).toBe('');
    expect(parsed.registration_url).toBe('');
});

test('accepts "Month Day, Year" as well as "Day Month Year"', () => {
    expect(parseHumanDate('August 28, 2026')).toBe('2026-08-28');
    expect(parseHumanDate('28 August 2026')).toBe('2026-08-28');
});

test('parseHumanDate returns empty for unreadable text', () => {
    expect(parseHumanDate('sometime next month')).toBe('');
    expect(parseHumanDate('')).toBe('');
});
