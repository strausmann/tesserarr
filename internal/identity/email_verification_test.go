package identity

import (
	"database/sql"
	"encoding/json"
	"testing"
)

// TestEmailVerificationString proves the exact three renderings
// cmd/tesserarr/main.go's "user list" relies on — see EmailVerification's
// own String comment for why EmailVerificationUnknown prints "unknown"
// rather than the "-" placeholder every other blank field in that output
// uses.
func TestEmailVerificationString(t *testing.T) {
	tests := []struct {
		name string
		v    EmailVerification
		want string
	}{
		{"unknown (zero value)", EmailVerificationUnknown, "unknown"},
		{"false", EmailVerificationFalse, "false"},
		{"true", EmailVerificationTrue, "true"},
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			if got := tc.v.String(); got != tc.want {
				t.Errorf("%v.String() = %q, want %q", tc.v, got, tc.want)
			}
		})
	}
}

// TestEmailVerificationSQLValueRoundTripsThroughNullInt64 proves sqlValue
// and emailVerificationFromNullInt64 are exact inverses for all three
// states, against the REAL identities.email_verified column (newAdminTestDB,
// same helper admin_test.go's own ListUsers tests use) rather than a
// synthetic table — in particular that EmailVerificationUnknown round-trips
// as a genuine SQL NULL (raw.Valid == false), never the integer 0, which
// migrations/0006_identity_email_verification.sql's own comment explains is
// the entire point: 0 must stay reserved for "a provider explicitly said
// false".
func TestEmailVerificationSQLValueRoundTripsThroughNullInt64(t *testing.T) {
	tests := []struct {
		name string
		v    EmailVerification
	}{
		{"unknown", EmailVerificationUnknown},
		{"false", EmailVerificationFalse},
		{"true", EmailVerificationTrue},
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			db := newAdminTestDB(t)
			insertAdminTestUser(t, db, "user-1", "")
			if _, err := db.Exec(
				`INSERT INTO identities (user_id, provider, subject, email_verified) VALUES (?, ?, ?, ?)`,
				"user-1", "oidc", "sub-1", tc.v.sqlValue(),
			); err != nil {
				t.Fatalf("insert sqlValue(): %v", err)
			}
			var raw sql.NullInt64
			if err := db.QueryRow(`SELECT email_verified FROM identities WHERE user_id = ?`, "user-1").Scan(&raw); err != nil {
				t.Fatalf("scan: %v", err)
			}
			if got := emailVerificationFromNullInt64(raw); got != tc.v {
				t.Errorf("round trip through sql.NullInt64 = %v, want %v (raw = %+v)", got, tc.v, raw)
			}
		})
	}
}

// TestOIDCEmailVerifiedClaimUnmarshalJSON proves oidcEmailVerifiedClaim
// accepts everything the doc comment promises — the spec-correct JSON
// boolean AND the JSON string real providers are known to send instead
// (see the type's own comment for the coreos/go-oidc precedent) — and
// rejects the rest rather than guessing.
func TestOIDCEmailVerifiedClaimUnmarshalJSON(t *testing.T) {
	tests := []struct {
		name    string
		json    string
		want    oidcEmailVerifiedClaim
		wantErr bool
	}{
		{"json boolean true", `true`, true, false},
		{"json boolean false", `false`, false, false},
		{"json string true (AWS Cognito/Elastic style)", `"true"`, true, false},
		{"json string false", `"false"`, false, false},
		{"json number is rejected", `1`, false, true},
		{"json null is rejected", `null`, false, true},
		{"garbage string is rejected", `"yes"`, false, true},
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			var got oidcEmailVerifiedClaim
			err := got.UnmarshalJSON([]byte(tc.json))
			if tc.wantErr {
				if err == nil {
					t.Fatalf("UnmarshalJSON(%s) succeeded, want an error", tc.json)
				}
				return
			}
			if err != nil {
				t.Fatalf("UnmarshalJSON(%s) returned error: %v", tc.json, err)
			}
			if got != tc.want {
				t.Errorf("UnmarshalJSON(%s) = %v, want %v", tc.json, got, tc.want)
			}
		})
	}
}

// TestOIDCEmailVerifiedClaimPointerIsNilWhenClaimOmitted proves the other
// half of emailVerificationFromClaim's contract: encoding/json leaves a
// *oidcEmailVerifiedClaim field nil when the surrounding JSON object omits
// "email_verified" entirely — no UnmarshalJSON call happens at all, which
// is exactly the "no source ever said" signal EmailVerificationUnknown
// depends on. This is standard encoding/json pointer-field behavior, not
// this package's own code, but the whole tri-state design in
// EmailVerification's doc comment rests on it — worth pinning down
// explicitly rather than trusting by assumption.
func TestOIDCEmailVerifiedClaimPointerIsNilWhenClaimOmitted(t *testing.T) {
	var claims struct {
		Email         string                  `json:"email"`
		EmailVerified *oidcEmailVerifiedClaim `json:"email_verified"`
	}
	if err := json.Unmarshal([]byte(`{"email":"person@example.com"}`), &claims); err != nil {
		t.Fatalf("json.Unmarshal() returned error: %v", err)
	}
	if claims.EmailVerified != nil {
		t.Errorf("EmailVerified = %v, want nil when the claim is entirely absent", claims.EmailVerified)
	}
	if got := emailVerificationFromClaim(claims.EmailVerified); got != EmailVerificationUnknown {
		t.Errorf("emailVerificationFromClaim(nil) = %v, want EmailVerificationUnknown", got)
	}
}

// TestEmailVerificationFromClaim covers emailVerificationFromClaim's three
// branches directly, complementing the round trip through real JSON above.
func TestEmailVerificationFromClaim(t *testing.T) {
	trueClaim := oidcEmailVerifiedClaim(true)
	falseClaim := oidcEmailVerifiedClaim(false)

	tests := []struct {
		name  string
		claim *oidcEmailVerifiedClaim
		want  EmailVerification
	}{
		{"nil claim", nil, EmailVerificationUnknown},
		{"claim true", &trueClaim, EmailVerificationTrue},
		{"claim false", &falseClaim, EmailVerificationFalse},
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			if got := emailVerificationFromClaim(tc.claim); got != tc.want {
				t.Errorf("emailVerificationFromClaim(%v) = %v, want %v", tc.claim, got, tc.want)
			}
		})
	}
}
