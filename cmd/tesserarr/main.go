// Command tesserarr runs the Tesserarr service.
package main

import (
	"fmt"
	"os"
)

func main() {
	if len(os.Args) < 2 {
		fmt.Fprintln(os.Stderr, "usage: tesserarr <serve|healthcheck|selftest>")
		os.Exit(2)
	}
	switch os.Args[1] {
	case "serve":
		fmt.Fprintln(os.Stderr, "serve is not implemented yet")
		os.Exit(1)
	default:
		fmt.Fprintf(os.Stderr, "unknown subcommand %q\n", os.Args[1])
		os.Exit(2)
	}
}
