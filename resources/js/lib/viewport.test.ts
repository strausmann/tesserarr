// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, it } from 'vitest';
import {
    listRoom,
    readViewport,
    subscribeViewport,
    trackVisibleViewport,
} from './viewport';

/** A window with a visual viewport a test can shrink like a keyboard would. */
function fakeWindow(withVisual = true) {
    const win = new EventTarget() as EventTarget & {
        innerHeight: number;
        visualViewport:
            | (EventTarget & { height: number; offsetTop: number })
            | null;
    };
    win.innerHeight = 800;
    const vv = Object.assign(new EventTarget(), { height: 800, offsetTop: 0 });
    win.visualViewport = withVisual ? vv : null;

    return {
        win: win as unknown as Window,
        keyboard: (height: number, offsetTop = 0) => {
            vv.height = height;
            vv.offsetTop = offsetTop;
            vv.dispatchEvent(new Event('resize'));
        },
        pan: (offsetTop: number) => {
            vv.offsetTop = offsetTop;
            vv.dispatchEvent(new Event('scroll'));
        },
        resize: (height: number) => {
            win.innerHeight = height;
            win.dispatchEvent(new Event('resize'));
        },
    };
}

describe('readViewport', () => {
    it('reads the visual viewport', () => {
        const { win, keyboard } = fakeWindow();
        keyboard(380, 12);
        expect(readViewport(win)).toEqual({ height: 380, offsetTop: 12 });
    });

    it('falls back to innerHeight without a visual viewport', () => {
        const { win } = fakeWindow(false);
        expect(readViewport(win)).toEqual({ height: 800, offsetTop: 0 });
    });
});

describe('trackVisibleViewport', () => {
    it('publishes --vvh and --vv-top and follows the keyboard', () => {
        const root = document.createElement('div');
        const { win, keyboard, pan } = fakeWindow();
        const stop = trackVisibleViewport(root, win);
        expect(root.style.getPropertyValue('--vvh')).toBe('800px');

        keyboard(420);
        expect(root.style.getPropertyValue('--vvh')).toBe('420px');
        pan(30);
        expect(root.style.getPropertyValue('--vv-top')).toBe('30px');

        stop();
        expect(root.style.getPropertyValue('--vvh')).toBe('');
        keyboard(300);
        expect(root.style.getPropertyValue('--vvh')).toBe('');
    });

    it('follows a resized window without a visual viewport', () => {
        const root = document.createElement('div');
        const { win, resize } = fakeWindow(false);
        trackVisibleViewport(root, win);
        resize(500);
        expect(root.style.getPropertyValue('--vvh')).toBe('500px');
    });
});

describe('subscribeViewport', () => {
    it('stops calling after the unsubscribe', () => {
        const { win, keyboard } = fakeWindow();
        let calls = 0;
        const stop = subscribeViewport(() => calls++, win);
        keyboard(400);
        stop();
        keyboard(300);
        expect(calls).toBe(1);
    });
});

describe('listRoom', () => {
    const opts = { gap: 4, max: 320, option: 44 };

    it('puts the list below the field when there is room', () => {
        expect(
            listRoom(
                { top: 100, bottom: 144 },
                { height: 800, offsetTop: 0 },
                opts,
            ),
        ).toEqual({ placement: 'bottom', maxHeight: 320 });
    });

    it('flips the list above the field when the keyboard leaves too little below', () => {
        expect(
            listRoom(
                { top: 300, bottom: 344 },
                { height: 400, offsetTop: 0 },
                opts,
            ),
        ).toEqual({ placement: 'top', maxHeight: 296 });
    });

    it('never makes the list shorter than two options', () => {
        expect(
            listRoom(
                { top: 10, bottom: 54 },
                { height: 60, offsetTop: 0 },
                opts,
            ).maxHeight,
        ).toBe(88);
    });
});
