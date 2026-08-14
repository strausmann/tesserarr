package config

import "testing"

func TestResolveForwardAuthHeadersAppliesPangolinPreset(t *testing.T) {
	headers, err := resolveForwardAuthHeaders("pangolin", ForwardAuthHeaders{})
	if err != nil {
		t.Fatalf("resolveForwardAuthHeaders() returned error: %v", err)
	}
	want := ForwardAuthHeaders{
		Subject:  "Remote-User-Id",
		Username: "Remote-User",
		Name:     "Remote-Name",
		Email:    "Remote-Email",
		Role:     "Remote-Role",
	}
	if headers != want {
		t.Errorf("headers = %+v, want %+v", headers, want)
	}
}

func TestResolveForwardAuthHeadersAutheliaPresetHasNoSubjectDefault(t *testing.T) {
	headers, err := resolveForwardAuthHeaders("authelia", ForwardAuthHeaders{})
	if err != nil {
		t.Fatalf("resolveForwardAuthHeaders() returned error: %v", err)
	}
	if headers.Subject != "" {
		t.Errorf("Subject = %q, want empty — authelia has no stable identity header", headers.Subject)
	}
	if headers.Username != "Remote-User" {
		t.Errorf("Username = %q, want Remote-User", headers.Username)
	}
}

func TestResolveForwardAuthHeadersOverrideWinsFieldByField(t *testing.T) {
	headers, err := resolveForwardAuthHeaders("pangolin", ForwardAuthHeaders{Subject: "X-Custom-Id"})
	if err != nil {
		t.Fatalf("resolveForwardAuthHeaders() returned error: %v", err)
	}
	if headers.Subject != "X-Custom-Id" {
		t.Errorf("Subject = %q, want the override X-Custom-Id", headers.Subject)
	}
	if headers.Username != "Remote-User" {
		t.Errorf("Username = %q, want the untouched preset default Remote-User", headers.Username)
	}
}

func TestResolveForwardAuthHeadersRejectsUnknownPreset(t *testing.T) {
	if _, err := resolveForwardAuthHeaders("no-such-preset", ForwardAuthHeaders{}); err == nil {
		t.Fatal("resolveForwardAuthHeaders() with an unknown preset returned no error")
	}
}
