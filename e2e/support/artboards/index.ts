// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

// The 0.1.0 artboards of the mockup canvas (docs/designs/0.1.0/README.md).
import { login } from './login';
import { loginPhone } from './login-phone';
import { invitePhone } from './invite-phone';
import { dashboard } from './dashboard';
import { dashboardPhone } from './dashboard-phone';
import { menuPhone } from './menu-phone';
import { status } from './status';
import { statusPhone } from './status-phone';
import { connections } from './connections';
import { connectionsPhone } from './connections-phone';
import { connectionEdit } from './connection-edit';
import { connectionEditPhone } from './connection-edit-phone';
import { connectionEditSyncthing } from './connection-edit-syncthing';
import { satellites } from './satellites';
import { setup } from './setup';
import { setupPhone } from './setup-phone';
import { shares } from './shares';
import { catalog } from './catalog';
import { catalogPhone } from './catalog-phone';
import { friendHome } from './friend-home';
import { friendHomePhone } from './friend-home-phone';
import { users } from './users';
import { usersPhone } from './users-phone';
import { userDetail } from './user-detail';
import { roles } from './roles';
import { tokens } from './tokens';
import { account } from './account';
import { accountPhone } from './account-phone';
import { settingsAuth } from './settings-auth';
import { settingsGeneral } from './settings-general';
import { settingsGeneralPhone } from './settings-general-phone';
import { audit } from './audit';
import type { ArtboardSpec } from './spec';

export type { ArtboardSpec } from './spec';

export const artboards: ArtboardSpec[] = [
    login,
    loginPhone,
    invitePhone,
    dashboard,
    dashboardPhone,
    menuPhone,
    status,
    statusPhone,
    connections,
    connectionsPhone,
    connectionEdit,
    connectionEditPhone,
    connectionEditSyncthing,
    satellites,
    setup,
    setupPhone,
    shares,
    catalog,
    catalogPhone,
    friendHome,
    friendHomePhone,
    users,
    usersPhone,
    userDetail,
    roles,
    tokens,
    account,
    accountPhone,
    settingsAuth,
    settingsGeneral,
    settingsGeneralPhone,
    audit,
];
