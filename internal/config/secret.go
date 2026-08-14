package config

import "encoding/json"

// redacted is what every formatting path prints instead of the value.
const redacted = "[REDACTED]"

// Secret is a string that refuses to print itself.
//
// Teil A redacted the session secret through methods on Config. A review showed
// the gap: a secret pulled out of the struct and printed on its own was
// unprotected — fmt.Println(cfg.SessionSecret), {{.SessionSecret}} in a
// template, reflection. Putting the redaction on the value itself closes that,
// because it travels with the value wherever it goes.
//
// Reveal is the single, deliberately awkward way to get the real value. Its
// name is a marker: every call site is a place worth looking at in a review.
type Secret string

// String implements fmt.Stringer — covers %v, %s, %q.
func (s Secret) String() string { return redacted }

// GoString implements fmt.GoStringer — covers %#v, which ignores Stringer.
func (s Secret) GoString() string { return redacted }

// MarshalJSON keeps the value out of every encoding/json path, including
// slog.JSONHandler, which reaches Kind Any values through json.Marshaler.
func (s Secret) MarshalJSON() ([]byte, error) { return json.Marshal(redacted) }

// UnmarshalJSON deliberately discards whatever it reads.
//
// Without it, decoding a document produced by MarshalJSON would put the literal
// placeholder into the field, and a later length check might accept it as a real
// secret. Discarding yields the empty string, which fails every check loudly.
func (s *Secret) UnmarshalJSON([]byte) error {
	*s = ""
	return nil
}

// Reveal returns the real value. Every call is a review point.
func (s Secret) Reveal() string { return string(s) }
