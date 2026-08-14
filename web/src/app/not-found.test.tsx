import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import NotFound from "./not-found";

describe("NotFound", () => {
  it("links back to the catalog, so a keyboard visitor always has somewhere to go", () => {
    render(<NotFound />);

    const link = screen.getByRole("link", { name: /back to tesserarr/i });
    expect(link).toHaveAttribute("href", "/");
  });
});
