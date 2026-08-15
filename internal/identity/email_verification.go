package identity

import (
	"database/sql"
	"fmt"
)

// EmailVerification is whether the source that supplied an identity's email
// (IdentitySummary.Email) also confirmed the address actually belongs to
// whoever is signing in — as opposed to merely NOT BEING FORGEABLE by the
// account holder, which is all IdentitySummary.Email's own trust already
// gets you (see its doc comment in admin.go for that distinction and why
// this had to become a separate field rather than a stronger claim folded
// into the existing one).
//
// This is deliberately a three-value type, not a bool: a forward-auth
// guard's header carries no verification signal at all (ForwardAuthClaims,
// forwardauth.go, has no field for it — there is nothing to read), and the
// OpenID Connect "email_verified" claim
// (https://openid.net/specs/openid-connect-core-1_0.html#StandardClaims) a
// provider MAY send alongside "email" is itself OPTIONAL — a provider is
// free to omit it entirely. EmailVerificationUnknown is what both of those
// map to, and it must never be confused with EmailVerificationFalse: "no
// source ever said" is not the same claim as "a source explicitly said
// no". An operator conflating the two would read an unconfirmed-by-design
// forward-auth identity as if some provider had actively rejected its
// email — a claim nothing here ever made.
type EmailVerification int

const (
	// EmailVerificationUnknown is the zero value, matching a genuine SQL
	// NULL in identities.email_verified (migrations/0006_identity_email_verification.sql)
	// — every forward-auth identity, permanently, and any OIDC identity
	// whose provider omitted the optional claim.
	EmailVerificationUnknown EmailVerification = iota
	// EmailVerificationFalse is an OIDC provider's own claim that this
	// email is NOT confirmed — a real, meaningful signal, not an absence
	// of one. See this type's own comment for why conflating it with
	// EmailVerificationUnknown would be wrong in the opposite direction.
	EmailVerificationFalse
	// EmailVerificationTrue is an OIDC provider's own claim that this
	// email IS confirmed.
	EmailVerificationTrue
)

// String renders v the way cmd/tesserarr/main.go's "user list" prints it —
// see listUsers' own doc comment for why "unknown" (not "-", the
// placeholder every OTHER blank field in that output uses) is what
// EmailVerificationUnknown gets: a blank field there means "nothing was
// recorded", but this is never blank — every identity has SOME value here,
// and "unknown" says so explicitly rather than looking like a missed
// column.
func (v EmailVerification) String() string {
	switch v {
	case EmailVerificationTrue:
		return "true"
	case EmailVerificationFalse:
		return "false"
	default:
		return "unknown"
	}
}

// sqlValue is what oidc.go's createUserFromInvite passes as the
// email_verified parameter of its INSERT into identities: nil for
// EmailVerificationUnknown (a genuine SQL NULL, not the integer 0 —
// migrations/0006_identity_email_verification.sql's own comment explains
// why the two must stay distinguishable), 0 or 1 otherwise.
func (v EmailVerification) sqlValue() any {
	switch v {
	case EmailVerificationTrue:
		return 1
	case EmailVerificationFalse:
		return 0
	default:
		return nil
	}
}

// emailVerificationFromNullInt64 converts what admin.go's ListUsers scans
// out of the nullable identities.email_verified column back into an
// EmailVerification — the read-side counterpart to sqlValue above.
func emailVerificationFromNullInt64(raw sql.NullInt64) EmailVerification {
	switch {
	case !raw.Valid:
		return EmailVerificationUnknown
	case raw.Int64 != 0:
		return EmailVerificationTrue
	default:
		return EmailVerificationFalse
	}
}

// oidcEmailVerifiedClaim is the JSON-unmarshal target oidc.go's Callback
// uses for an ID token's optional "email_verified" claim
// (https://openid.net/specs/openid-connect-core-1_0.html#StandardClaims).
//
// A plain *bool very nearly works on its own — encoding/json already
// leaves a pointer field nil when the provider omits the claim entirely,
// exactly the signal EmailVerificationUnknown needs — except that some
// real providers send it as the JSON STRING "true"/"false" instead of the
// JSON boolean the spec actually defines. This is not a hypothetical: the
// coreos/go-oidc library this package already depends on carries its own,
// unexported workaround for exactly this in its UserInfo type (see
// stringAsBool in github.com/coreos/go-oidc/v3/oidc, with the AWS Cognito
// and Elastic forum threads it links as evidence), unusable here because
// it is unexported — this type re-implements the identical tolerance for
// oidc.go's own claims struct.
type oidcEmailVerifiedClaim bool

// UnmarshalJSON accepts the JSON boolean the OpenID Connect spec defines
// AND the JSON string real providers are known to send instead — see this
// type's own doc comment for why both are handled rather than only the
// spec-correct one. Anything else (a number, an object, null spelled out
// explicitly) is rejected rather than silently guessed at: idToken.Claims
// already fails the same way for an "email" claim of the wrong JSON type
// (see oidc_test.go's forceEmailNumber), and a malformed email_verified
// claim deserves the identical treatment — a caller finding out the
// provider sent something it cannot make sense of, not a value invented
// on its behalf.
func (c *oidcEmailVerifiedClaim) UnmarshalJSON(b []byte) error {
	switch string(b) {
	case "true", `"true"`:
		*c = true
	case "false", `"false"`:
		*c = false
	default:
		return fmt.Errorf("identity: unexpected email_verified claim value %s", b)
	}
	return nil
}

// emailVerificationFromClaim converts oidc.go's parsed
// *oidcEmailVerifiedClaim (nil when the provider sent no email_verified
// claim at all) into the EmailVerification this package stores and
// prints.
func emailVerificationFromClaim(claim *oidcEmailVerifiedClaim) EmailVerification {
	switch {
	case claim == nil:
		return EmailVerificationUnknown
	case bool(*claim):
		return EmailVerificationTrue
	default:
		return EmailVerificationFalse
	}
}
