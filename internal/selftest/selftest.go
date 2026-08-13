// Package selftest checks a running instance from the outside.
//
// It exists because the health path proves too little: it is deliberately
// exempt from every check, so a passing probe says "the process runs" and
// nothing about whether the service is usable. The selftest asks the questions
// the health path cannot answer.
package selftest

import (
	"fmt"
	"net/http"
	"strings"
	"time"
)

// Run checks a running instance reachable at baseURL and returns one error per
// problem found. An empty slice means every check passed.
func Run(baseURL string, client *http.Client) []error {
	if client == nil {
		client = &http.Client{Timeout: 10 * time.Second}
	}
	base := strings.TrimSuffix(baseURL, "/")

	var problems []error

	if err := expectStatus(client, base+"/healthz", http.StatusOK); err != nil {
		problems = append(problems, fmt.Errorf("health path: %w", err))
	}

	// A session-checked path must reject a request without a cookie. Checking
	// the status code rather than the body keeps this honest: 401 means the
	// check ran, 200 means it did not.
	if err := expectStatus(client, base+"/api/v1/app/session", http.StatusUnauthorized); err != nil {
		problems = append(problems, fmt.Errorf("session path without cookie: %w", err))
	}

	return problems
}

func expectStatus(client *http.Client, url string, want int) error {
	resp, err := client.Get(url)
	if err != nil {
		return fmt.Errorf("request failed: %w", err)
	}
	defer func() { _ = resp.Body.Close() }()

	if resp.StatusCode != want {
		return fmt.Errorf("status %d, want %d", resp.StatusCode, want)
	}
	return nil
}
