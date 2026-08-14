package config

import (
	"encoding/json"
	"fmt"
	"strings"
	"testing"
)

const marker = "SECRET-MARKER-9f3a2b1c"

func TestSecretRedactsAcrossFormattingPaths(t *testing.T) {
	s := Secret(marker)

	cases := map[string]string{
		"%v":         fmt.Sprintf("%v", s),
		"%+v":        fmt.Sprintf("%+v", s),
		"%s":         fmt.Sprintf("%s", s),
		"%q":         fmt.Sprintf("%q", s),
		"%#v":        fmt.Sprintf("%#v", s),
		"pointer %v": fmt.Sprintf("%v", &s),
	}
	for name, out := range cases {
		if strings.Contains(out, marker) {
			t.Errorf("%s leaked the secret: %s", name, out)
		}
	}

	encoded, err := json.Marshal(s)
	if err != nil {
		t.Fatalf("json.Marshal returned error: %v", err)
	}
	if strings.Contains(string(encoded), marker) {
		t.Errorf("json.Marshal leaked the secret: %s", encoded)
	}
}

// TestSecretRedactsWhenNestedInAForeignStruct is the case the whole type exists
// for: Teil A's methods sat on Config, so a secret pulled out of it and printed
// on its own was unprotected.
func TestSecretRedactsWhenNestedInAForeignStruct(t *testing.T) {
	type wrapper struct {
		Note   string
		Hidden Secret
	}
	w := wrapper{Note: "visible", Hidden: Secret(marker)}

	for _, out := range []string{
		fmt.Sprintf("%v", w),
		fmt.Sprintf("%+v", w),
		fmt.Sprintf("%#v", w),
	} {
		if strings.Contains(out, marker) {
			t.Errorf("nested secret leaked: %s", out)
		}
	}

	encoded, err := json.Marshal(w)
	if err != nil {
		t.Fatalf("json.Marshal returned error: %v", err)
	}
	if strings.Contains(string(encoded), marker) {
		t.Errorf("nested secret leaked through json: %s", encoded)
	}
}

// TestSecretRevealReturnsTheRealValue — the value must still be usable.
func TestSecretRevealReturnsTheRealValue(t *testing.T) {
	s := Secret(marker)
	if s.Reveal() != marker {
		t.Errorf("Reveal() = %q, want %q", s.Reveal(), marker)
	}
}

// TestSecretJSONRoundTripYieldsEmptyNotThePlaceholder closes the gap Teil A
// left open: Marshal followed by Unmarshal used to yield the placeholder.
//
// The round trip does NOT preserve the value — the secret never made it into
// the JSON in the first place, so there is nothing to recover. What matters is
// what comes back instead: Teil A's approach returned the literal placeholder
// "REDACTED", which a later length check could mistake for a real secret. The
// empty string fails every such check loudly instead of silently passing one.
func TestSecretJSONRoundTripYieldsEmptyNotThePlaceholder(t *testing.T) {
	type holder struct {
		S Secret
	}
	encoded, err := json.Marshal(holder{S: Secret(marker)})
	if err != nil {
		t.Fatalf("Marshal returned error: %v", err)
	}

	var back holder
	if err := json.Unmarshal(encoded, &back); err != nil {
		t.Fatalf("Unmarshal returned error: %v", err)
	}
	if back.S.Reveal() == marker {
		t.Fatal("round trip preserved the secret — that is not what this type promises")
	}
	if back.S.Reveal() != "" {
		t.Errorf("round trip yielded %q, want the empty string", back.S.Reveal())
	}
}
