import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { LOGIN_START_URL } from "@/lib/auth-links";
import LoginPage from "./page";

describe("LoginPage", () => {
  it("links to LOGIN_START_URL, the single source of truth for the login route", () => {
    render(<LoginPage />);

    const link = screen.getByRole("link", { name: /continue to sign in/i });
    expect(link).toHaveAttribute("href", LOGIN_START_URL);
  });
});
