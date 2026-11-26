// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ArtboardExpectation, ArtboardState } from '../views';

/**
 * What one artboard of the approved 0.1.0 mockup canvas shows
 * (docs/designs/0.1.0/README.md). A UI task that builds the view takes the
 * `expect` of its artboard into `gatedViews` (e2e/support/views.ts) and adds
 * a state fixture for every state (e2e/support/artboard.ts).
 *
 * What is in here, and what is not:
 *
 * - Only what the artboard shows in its default state. A dialog, a banner
 *   after an action or a field that appears on demand is not listed; the task
 *   that builds it adds it with the state that shows it.
 * - Every heading and control must appear exactly once on the page
 *   (`assertArtboard`), so a control that repeats per row (a table row's
 *   "Open") is left out, and so is the clear button of a field (it shows only
 *   while the field has a value).
 * - The app frame (menu, main navigation, sign-out) belongs to every view and
 *   is checked once by the frame task, not per artboard. `Menu-Phone` is the
 *   open menu itself.
 * - Sections the canvas marks "Vorschau 0.2.0" (or later) are left out.
 * - `expect` holds what the artboard shows in every one of its states, because
 *   `assertArtboardStates` checks the whole expectation again in each state.
 *   What only one state shows (the list when there is content, the hint when
 *   there is none) is in `inState`; the state fixture of the UI task asserts
 *   it.
 * - Names are the German labels of the canvas and its example names (Alex,
 *   Sam, Sonarr HD, Keller). The view runs in German when it is checked
 *   against them.
 */
export type ArtboardSpec = {
    /** File name of the artboard in the canvas. */
    artboard: string;
    /** Title of the artboard in the canvas. */
    title: string;
    /** The artboard is drawn for 1440 px (desktop) or 390 × 844 px (phone). */
    viewport: 'desktop' | 'phone';
    expect: ArtboardExpectation;
    /** Headings, controls (`role:name`) and texts that only this state shows. */
    inState?: Partial<
        Record<
            ArtboardState,
            { headings?: string[]; controls?: string[]; texts?: string[] }
        >
    >;
};
